<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\TaxBundle\Twig\Extension;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\Result\CalculationResult;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Enum\TaxDirection;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Symfony\Component\Uid\Ulid;
use Twig\Attribute\AsTwigFunction;
use WeakMap;

final class TaxBreakdownExtension
{
    /**
     * @var WeakMap<BaseInvoice|Quote, CalculationResult>
     */
    private WeakMap $cache;

    public function __construct(
        private readonly TaxIdentifierRepository $taxIdentifierRepository,
        private readonly CompanySelector $companySelector,
        private readonly TaxCalculatorInterface $taxCalculator,
        private readonly SystemConfig $systemConfig,
    ) {
        $this->cache = new WeakMap();
    }

    /**
     * The legal wording a company outside the scope of VAT must print on every
     * invoice and quote, or null when it is liable and there is nothing to say.
     *
     * Exposed as one function so the eight billing designs, the default PDF and
     * the client portal all ask the same question, instead of each deciding for
     * itself whether to print something and what.
     */
    #[AsTwigFunction(name: 'vat_exempt_mention')]
    public function vatExemptMention(): ?string
    {
        if (! $this->systemConfig->isVatExempt()) {
            return null;
        }

        return $this->systemConfig->vatExemptMention();
    }

    /**
     * The mention a document issued under the option for VAT on debits must
     * carry (CGI, ann. II, art. 242 nonies A, I-11° bis), or null.
     *
     * Read off the document, where the option was frozen when it was issued,
     * never off the current setting: a document does not change its wording
     * because the company changed its mind afterwards.
     */
    #[AsTwigFunction(name: 'vat_on_debits_mention')]
    public function vatOnDebitsMention(mixed $document): ?string
    {
        return $document instanceof BaseInvoice && $document->isVatOnDebits()
            ? BaseInvoice::VAT_ON_DEBITS_MENTION
            : null;
    }

    /**
     * Returns an ordered list of TaxIdentifier entities for the given owner.
     *
     * @return list<TaxIdentifier>
     */
    #[AsTwigFunction(name: 'tax_identifiers')]
    public function taxIdentifiers(Client | Company | null $owner = null): array
    {
        $identifiers = $owner instanceof Client
            ? $this->forClient($owner)
            : $this->forCompany($owner);

        usort($identifiers, static function (TaxIdentifier $a, TaxIdentifier $b): int {
            if ($a->isPrimary() !== $b->isPrimary()) {
                return $a->isPrimary() ? -1 : 1;
            }

            return strcasecmp((string) $a->getLabel(), (string) $b->getLabel());
        });

        return $identifiers;
    }

    /**
     * Compute or retrieve a cached {@see CalculationResult} for the document, then
     * shape it into a structure templates can iterate without reaching into the
     * domain objects.
     *
     * Shape:
     *  - subTotal:       BigNumber
     *  - lineTaxRows:    list<TaxSummaryRow>    line-level taxes (Additive)
     *  - additiveRows:   list<TaxSummaryRow>    invoice-level Additive taxes
     *  - deductiveRows:  list<TaxSummaryRow>    invoice-level Deductive (withholding)
     *  - informationalRows: list<TaxSummaryRow> invoice-level Informational rows
     *  - total:          BigNumber
     *  - totalLineTax:   BigNumber
     *  - totalWithholding: BigNumber
     *  - amountPayable:  BigNumber
     *
     * @return array<string, mixed>
     */
    #[AsTwigFunction(name: 'tax_breakdown')]
    public function taxBreakdown(BaseInvoice | Quote $document): array
    {
        $result = $this->cache[$document] ?? null;

        if ($result === null) {
            $result = $this->taxCalculator->calculate($document);
            $this->cache[$document] = $result;
        }

        $lineTaxRows = [];
        $additiveRows = [];
        $deductiveRows = [];
        $informationalRows = [];

        foreach ($result->summaryRows as $row) {
            // Line-level rows are always Additive with note=null.
            // Invoice-level rows can be in any direction.
            if ($row->direction === TaxDirection::Deductive) {
                $deductiveRows[] = $row;
                continue;
            }

            if ($row->direction === TaxDirection::Informational) {
                $informationalRows[] = $row;
                continue;
            }

            // Additive: split between line-level (note=null) and invoice-level by sequence.
            // Heuristic: line-level rows are merged across all lines and carry note=null;
            // invoice-level Additive rows always come from InvoiceTax (note may still be null).
            // Use the invoice-level breakdown's own row list to decide which is which.
            if ($this->isInvoiceLevelRow($row, $result)) {
                $additiveRows[] = $row;
            } else {
                $lineTaxRows[] = $row;
            }
        }

        return [
            'subTotal' => $result->subTotal,
            'lineTaxRows' => $lineTaxRows,
            'additiveRows' => $additiveRows,
            'deductiveRows' => $deductiveRows,
            'informationalRows' => $informationalRows,
            'totalLineTax' => $result->totalLineTax,
            'totalAdditive' => $result->invoiceLevelBreakdown->totalInvoiceLevelTax,
            'totalWithholding' => $result->totalWithholding,
            'total' => $result->total,
            'amountPayable' => $result->amountPayable,
        ];
    }

    /**
     * Returns the persisted payable amount on the document. Falls back to
     * `total - withholding` recomputed when the document has not been saved
     * since US-008 (e.g., a draft never run through TotalCalculator).
     */
    #[AsTwigFunction(name: 'payable_amount')]
    public function payableAmount(BaseInvoice | Quote $document): BigNumber
    {
        $persisted = $document->getPayableAmount();

        if (! $persisted->isZero()) {
            return $persisted;
        }

        return BigDecimal::of($document->getTotal())->minus(BigDecimal::of($document->getWithholdingAmount()));
    }

    private function isInvoiceLevelRow(TaxSummaryRow $row, CalculationResult $result): bool
    {
        return array_any($result->invoiceLevelBreakdown->taxRows, fn ($candidate) => $candidate->name === $row->name
        && $candidate->rate === $row->rate
        && $candidate->direction === $row->direction
        && ($candidate->note ?? '') === ($row->note ?? ''));
    }

    /**
     * @return list<TaxIdentifier>
     */
    private function forClient(Client $client): array
    {
        return array_values($client->getTaxIdentifiers()->toArray());
    }

    /**
     * @return list<TaxIdentifier>
     */
    private function forCompany(?Company $company): array
    {
        $companyId = $company?->getId() ?? $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return [];
        }

        return $this->taxIdentifierRepository->findCompanyIdentifiers($companyId);
    }
}
