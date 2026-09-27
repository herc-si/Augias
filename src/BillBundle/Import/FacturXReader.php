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

namespace Augias\BillBundle\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTime;
use DateTimeImmutable;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use Throwable;
use function ltrim;
use function str_starts_with;
use function trim;

/**
 * Reads the invoice a Factur-X (or ZUGFeRD) PDF carries inside it, or the
 * same XML on its own — no OCR, nothing guessed: the figures are the
 * supplier's own.
 *
 * Returns null for anything else — a plain PDF, a picture — which has to be
 * typed in.
 *
 * @see \Augias\BillBundle\Tests\Import\FacturXImportTest
 */
final class FacturXReader
{
    public function read(string $content): ?SupplierInvoice
    {
        try {
            $document = str_starts_with($content, '%PDF')
                ? ZugferdDocumentPdfReader::readAndGuessFromContent($content)
                : (str_starts_with(ltrim($content), '<') ? ZugferdDocumentReader::readAndGuessFromContent($content) : null);
        } catch (Throwable) {
            // No embedded invoice, or one the library does not know.
            return null;
        }

        if (! $document instanceof ZugferdDocumentReader) {
            return null;
        }

        $document->getDocumentInformation($number, $typeCode, $issueDate, $currency, $taxCurrency, $name, $language, $period);
        $document->getDocumentSeller($sellerName, $sellerIds, $description);
        $document->getDocumentSellerLegalOrganisation($legalId, $legalType, $legalName);
        $document->getDocumentSellerTaxRegistration($taxRegistrations);
        $document->getDocumentSummation($grandTotal, $duePayable, $lineTotal, $chargeTotal, $allowanceTotal, $taxBasisTotal, $taxTotal, $rounding, $prepaid);

        $dueDate = null;

        if ($document->firstDocumentPaymentTerms()) {
            $document->getDocumentPaymentTerm($termDescription, $dueDate, $mandate);
        }

        return new SupplierInvoice(
            number: $this->text($number),
            issueDate: $this->date($issueDate),
            dueDate: $this->date($dueDate),
            currency: $this->text($currency) ?? 'EUR',
            // What the invoice asks to be paid, before any deposit taken off.
            total: $this->amount($grandTotal) ?? BigDecimal::zero(),
            tax: $this->amount($taxTotal),
            sellerName: $this->text($sellerName) ?? $this->text($legalName),
            sellerIdentifier: $this->text($legalId) ?? $this->text($taxRegistrations['VA'] ?? null),
            typeCode: $this->text($typeCode) ?? '380',
        );
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function date(?DateTime $date): ?DateTimeImmutable
    {
        return null === $date ? null : DateTimeImmutable::createFromMutable($date)->setTime(0, 0);
    }

    /**
     * The library hands amounts over as floats; they are brought back to the
     * cents the invoice was written in.
     */
    private function amount(?float $value): ?BigDecimal
    {
        return null === $value ? null : BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfEven);
    }
}
