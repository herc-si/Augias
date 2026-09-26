<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Fec;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\CoreBundle\Entity\Company;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function in_array;
use function preg_replace;
use function range;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * The FEC — fichier des écritures comptables — of a financial year, as the
 * tax administration asks for it (LPF art. L47 A and A47 A-1).
 *
 * Laid out from Augias's own books, which are single-entry and kept on a
 * cash basis: each receipt, purchase or expense becomes a balanced entry on
 * the chart of accounts, see FecAccounts. The sales and received-bills
 * journals are left out: they only date when VAT fell due, and that VAT is
 * already in the receipts and purchases it belongs to — taking both would
 * count it twice.
 *
 * A professional in BNC keeping cash accounts gets the list of section VIII,
 * with how each amount was settled and who it was with; everyone else the
 * eighteen fields of section VII. Flat file, tab-separated, one header line,
 * dates as AAAAMMJJ, decimals with a comma, UTF-8; entries numbered from 1
 * in date order with no gap; named SirenFECAAAAMMJJ after the year's last day.
 *
 * @see \Augias\AccountingBundle\Tests\Fec\FecGeneratorTest
 */
final readonly class FecGenerator
{
    /** The books a FEC is laid out from: money that actually moved. */
    private const array BOOKS = [LedgerBook::Revenue, LedgerBook::Purchase, LedgerBook::Expense];

    private const array JOURNALS = [
        'revenue' => ['RE', 'Livre des recettes'],
        'purchase' => ['AC', 'Registre des achats'],
        'expense' => ['DE', 'Dépenses'],
    ];

    private const array SETTLEMENT = [
        'bank_transfer' => 'Virement',
        'check' => 'Chèque',
        'credit_card' => 'Carte bancaire',
        'direct_debit' => 'Prélèvement',
        'cash' => 'Espèces',
        'online' => 'Paiement en ligne',
        'other' => 'Autre',
    ];

    private const array NATURE = [
        'revenue' => 'Recette professionnelle',
        'purchase' => 'Achat',
        'expense' => 'Dépense professionnelle',
    ];

    public function __construct(
        private LedgerEntryRepository $entries,
        private AccountingProfileProvider $profiles,
        private TaxIdentifierRepository $identifiers,
    ) {
    }

    public function variant(Company $company): FecVariant
    {
        return ActivityNature::ServicesBnc === $this->profiles->forCompany($company)->primaryActivity
            ? FecVariant::CashBnc
            : FecVariant::Standard;
    }

    /**
     * The financial years that have entries, the latest first — each named
     * after the year it opened in.
     *
     * @return list<int>
     */
    public function years(Company $company, DateTimeImmutable $today): array
    {
        $first = $this->entries->earliestEntryDate($company);

        if (! $first instanceof DateTimeImmutable) {
            return [];
        }

        $month = $this->profiles->forCompany($company)->fiscalYearStartMonth;

        return range(PeriodType::Year->yearOf($today, $month), PeriodType::Year->yearOf($first, $month), -1);
    }

    public function generate(Company $company, int $year): FecFile
    {
        $profile = $this->profiles->forCompany($company);
        $opening = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $profile->fiscalYearStartMonth));
        $from = PeriodType::Year->startOf($opening, $profile->fiscalYearStartMonth);
        $to = PeriodType::Year->endOf($opening, $profile->fiscalYearStartMonth);
        $variant = $this->variant($company);

        $rows = [implode("\t", $variant->fields())];
        $number = 0;

        foreach ($this->entries->findForFec($company, $from, $to, self::BOOKS) as $entry) {
            ++$number;

            foreach ($this->lines($entry) as [$account, $debit, $credit]) {
                $rows[] = implode("\t", $this->row($entry, $number, $account, $debit, $credit, $variant));
            }
        }

        $siren = $this->siren($company);
        $filename = sprintf('%sFEC%s.txt', $siren ?? '', $to->format('Ymd'));

        return new FecFile(
            $filename,
            implode("\r\n", $rows) . "\r\n",
            $this->notice($company, $variant, $from, $to, $number, $siren),
            $variant,
            $from,
            $to,
            $number,
        );
    }

    /**
     * The entry as balanced lines: account, debit, credit — in minor units.
     *
     * The treasury side carries net plus tax, so that each entry balances to
     * the cent whatever its stored total says. A negative entry — a refund, a
     * reversal — has its sides swapped.
     *
     * @return list<array{string, BigDecimal, BigDecimal}>
     */
    private function lines(LedgerEntry $entry): array
    {
        $amount = BigDecimal::of($entry->getAmount());
        $tax = null === $entry->getTaxAmount() ? BigDecimal::zero() : BigDecimal::of($entry->getTaxAmount());
        $net = null === $entry->getNetAmount() ? $amount->minus($tax) : BigDecimal::of($entry->getNetAmount());
        $negative = $net->plus($tax)->isNegative();
        [$net, $tax] = [$net->abs(), $tax->abs()];

        $book = $entry->getBook();
        $zero = BigDecimal::zero();
        $treasury = FecAccounts::treasury($entry->getSettlementMethod());
        $counterpart = FecAccounts::counterpart($book, $entry->getActivityNature());
        $incoming = LedgerBook::Revenue === $book;

        // Money in: the bank is debited, the sale and its VAT credited. Money
        // out the other way round.
        $lines = [[$treasury, $incoming, $net->plus($tax)], [$counterpart, ! $incoming, $net]];

        if (! $tax->isZero()) {
            $lines[] = [FecAccounts::vat($book), ! $incoming, $tax];
        }

        return array_map(
            static fn (array $line): array => ($line[1] xor $negative) ? [$line[0], $line[2], $zero] : [$line[0], $zero, $line[2]],
            $lines,
        );
    }

    /**
     * @return list<string>
     */
    private function row(LedgerEntry $entry, int $number, string $account, BigDecimal $debit, BigDecimal $credit, FecVariant $variant): array
    {
        $book = $entry->getBook()->value;
        [$journal, $journalLabel] = self::JOURNALS[$book];
        $date = $entry->getEntryDate()->format('Ymd');
        $foreign = 'EUR' !== $entry->getCurrencyCode();

        $row = [
            $journal,
            $journalLabel,
            (string) $number,
            $date,
            $account,
            FecAccounts::label($account),
            '',
            '',
            self::text($entry->getDocumentReference() ?: sprintf('%s-%d', $journal, (int) $entry->getSequenceNumber())),
            $date,
            self::text($entry->getLabel()),
            self::amount($debit),
            self::amount($credit),
            '',
            '',
            ($entry->getLockedAt() ?? $entry->getEntryDate())->format('Ymd'),
            $foreign ? self::amount($debit->plus($credit)) : '',
            $foreign ? $entry->getCurrencyCode() : '',
        ];

        if (FecVariant::CashBnc === $variant) {
            $method = $entry->getSettlementMethod();
            $row = [
                ...$row,
                $date,
                $method instanceof SettlementMethod ? self::SETTLEMENT[$method->value] : '',
                self::NATURE[$book],
                self::text($entry->getCounterpartyName()),
            ];
        }

        return $row;
    }

    /**
     * Minor units to a decimal with a comma, as the administration reads them.
     */
    private static function amount(BigNumber $minor): string
    {
        return str_replace('.', ',', (string) $minor->toBigDecimal()->dividedBy(100, 2, RoundingMode::HalfEven));
    }

    /**
     * Free text on one line, without the separator.
     */
    private static function text(string $text): string
    {
        return trim((string) preg_replace('/[\t\r\n|]+/', ' ', $text));
    }

    private function siren(Company $company): ?string
    {
        foreach ($this->identifiers->findCompanyIdentifiers($company->getId()) as $identifier) {
            $value = (string) preg_replace('/\D+/', '', (string) $identifier->getValue());

            if (in_array($identifier->getLabel(), ['SIRET', 'SIREN'], true) && strlen($value) >= 9) {
                return substr($value, 0, 9);
            }
        }

        return null;
    }

    private function notice(Company $company, FecVariant $variant, DateTimeImmutable $from, DateTimeImmutable $to, int $entries, ?string $siren): string
    {
        $fields = $variant->fields();
        $accounts = implode("\n", array_map(static fn (int | string $number, string $label): string => sprintf('  %-6s %s', $number, $label), array_keys(FecAccounts::LABELS), FecAccounts::LABELS));

        return implode("\n", [
            'NOTICE DESCRIPTIVE DU FICHIER DES ÉCRITURES COMPTABLES',
            '(article A47 A-1 du livre des procédures fiscales)',
            '',
            sprintf('Entreprise : %s%s', $company->getName(), null === $siren ? '' : ' — SIREN ' . $siren),
            sprintf('Exercice : du %s au %s — %d écriture(s)', $from->format('d/m/Y'), $to->format('d/m/Y'), $entries),
            sprintf('Liste de champs : %s (%d champs)', FecVariant::CashBnc === $variant ? 'section VIII (BNC, comptabilité de trésorerie)' : 'section VII', count($fields)),
            '',
            'FORMAT',
            '  Fichier à plat, organisation séquentielle, longueur variable.',
            '  Jeu de caractères : UTF-8. Séparateur de zones : tabulation. Séparateur d\'enregistrements : CR LF.',
            '  Première ligne : nom des champs. Dates au format AAAAMMJJ. Montants en euros, virgule décimale.',
            '  Écritures numérotées de 1 à ' . $entries . ', dans l\'ordre chronologique, sans rupture.',
            '',
            'CHAMPS',
            '  ' . implode(', ', $fields),
            '  CompAuxNum, CompAuxLib, EcritureLet et DateLet ne sont pas servis : la comptabilité ne tient pas de comptes de tiers.',
            '  ValidDate : date de scellement de la période, ou date de l\'écriture pour une période non encore scellée.',
            '  Montantdevise et Idevise ne sont servis que pour une écriture tenue dans une autre devise que l\'euro.',
            '',
            'NATURE DE LA COMPTABILITÉ',
            '  Comptabilité de trésorerie : chaque écriture retrace un encaissement ou un décaissement effectif.',
            '  Les livres tenus en partie simple sont présentés en partie double sur les comptes suivants :',
            $accounts,
            '  Recette : débit 512 (530 si espèces) ; crédit 706 ou 707 pour le montant hors taxe, 44571 pour la TVA.',
            '  Achat ou dépense : débit 607, 604 ou 628 pour le hors taxe, 44566 pour la TVA ; crédit 512 ou 530.',
            '  Un montant négatif (remboursement, contrepassation) inverse le débit et le crédit.',
            '',
            'JOURNAUX',
            '  RE  Livre des recettes',
            '  AC  Registre des achats',
            '  DE  Dépenses',
            '  Le journal des ventes et celui des factures reçues, qui ne font que dater l\'exigibilité de la TVA',
            '  sur les livraisons de biens et sous l\'option pour les débits, ne sont pas repris : cette TVA figure',
            '  déjà dans les écritures d\'encaissement et de décaissement auxquelles elle se rapporte.',
            ...(FecVariant::CashBnc === $variant ? [
                '',
                'CODES',
                '  ModeRglt : ' . implode(', ', self::SETTLEMENT),
                '  NatOp : ' . implode(', ', self::NATURE),
                '  IdClient : nom du client ou du fournisseur.',
            ] : []),
            '',
        ]);
    }
}
