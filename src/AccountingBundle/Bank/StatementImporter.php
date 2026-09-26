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

namespace Augias\AccountingBundle\Bank;

use Augias\AccountingBundle\Bank\Parser\StatementParser;
use Augias\AccountingBundle\Bank\Parser\StatementText;
use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\MoneyBundle\Currency\CurrencyScale;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;
use Money\Currency;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use function array_keys;
use function count;
use function hash;
use function strtoupper;
use function trim;

/**
 * Reads a statement file into an account's lines.
 *
 * Importing the same statement twice, or two that overlap, adds nothing
 * twice: each line carries a fingerprint — the bank's reference when the
 * format has one, else its date, amount and label, numbered when a file holds
 * identical lines (two coffees on the same day are two lines).
 *
 * @see \Augias\AccountingBundle\Tests\Bank\StatementImporterTest
 */
final readonly class StatementImporter
{
    /**
     * @param iterable<StatementParser> $parsers most specific first
     */
    public function __construct(
        #[AutowireIterator(StatementParser::TAG)]
        private iterable $parsers,
        private BankTransactionRepository $transactions,
        private EntityManagerInterface $entityManager,
        private CurrencyScale $scale = new CurrencyScale(),
    ) {
    }

    /**
     * @throws UnreadableStatement
     */
    public function import(BankAccount $account, string $content): ImportResult
    {
        $content = StatementText::utf8($content);

        if ('' === trim($content)) {
            throw new UnreadableStatement('empty');
        }

        $parser = $this->parserFor($content);
        $currency = new Currency($account->getCurrencyCode());
        $lines = [];
        $occurrences = [];

        foreach ($parser->parse($content) as $parsed) {
            if (null !== $parsed->currency && strtoupper($parsed->currency) !== $account->getCurrencyCode()) {
                throw new UnreadableStatement('currency', ['%statement%' => strtoupper($parsed->currency), '%account%' => $account->getCurrencyCode()]);
            }

            $amount = $this->scale->toMinorUnit($parsed->amount, $currency)->toScale(0, RoundingMode::HalfEven)->toBigInteger();

            if (null !== $parsed->reference) {
                $fingerprint = hash('sha256', 'ref|' . $parsed->reference);
            } else {
                $key = $parsed->date->format('Y-m-d') . '|' . $amount . '|' . $parsed->label;
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
                $fingerprint = hash('sha256', $key . '|' . $occurrences[$key]);
            }

            $lines[$fingerprint] ??= new BankTransaction($account, $parsed->date, $amount, $parsed->label, $fingerprint)
                ->setCounterpartyName($parsed->counterparty)
                ->setReference($parsed->reference);
        }

        $known = $this->transactions->knownFingerprints($account, array_keys($lines));
        $imported = 0;

        foreach ($lines as $fingerprint => $line) {
            if (isset($known[$fingerprint])) {
                continue;
            }

            $this->entityManager->persist($line);
            ++$imported;
        }

        $this->entityManager->flush();

        return new ImportResult($parser->name(), $imported, count($lines) - $imported);
    }

    private function parserFor(string $content): StatementParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content)) {
                return $parser;
            }
        }

        throw new UnreadableStatement('format');
    }
}
