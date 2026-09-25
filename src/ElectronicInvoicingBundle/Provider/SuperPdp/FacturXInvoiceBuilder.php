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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use const JSON_THROW_ON_ERROR;
use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Calculator\Result\TaxSummaryRow;
use Augias\TaxBundle\Calculator\TaxCalculatorInterface;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Brick\Math\BigNumber;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdSchemeIdentifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVATExemptionReasonCode;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use JsonException;
use Twig\Environment;
use function array_values;
use function ctype_digit;
use function json_decode;
use function strlen;
use function substr;

/**
 * Builds a Factur-X document (a PDF/A-3 with an embedded CII XML, EN16931
 * profile) for an {@see Invoice}, ready to be uploaded to SUPER PDP.
 *
 * Reuses the same Twig template and mpdf renderer as the regular invoice PDF
 * (see \Augias\InvoiceBundle\Action\View) as the human-readable layer,
 * and horstoeko/zugferd to build the CII XML and merge it into that PDF.
 *
 * Simplification for this first version: a line with more than one applied
 * tax rate (compound taxes) only has its first tax rate reflected in the CII
 * document — Augias's SIRET-gated eligibility targets standard French
 * B2B invoicing, where a line normally carries a single VAT rate.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\FacturXInvoiceBuilderTest
 */
final readonly class FacturXInvoiceBuilder
{
    /** UNTDID 2475 "5": the tax falls due on the invoice date. */
    private const string VAT_DUE_ON_INVOICE_DATE = '5';

    /**
     * BT-34/BT-49 (seller/buyer electronic address): SUPER PDP's directory only
     * resolves French recipients under Peppol scheme 0225 ("FRCTC electronic
     * address"), keyed on the 9-digit SIREN — confirmed against their API, which
     * rejects any other scheme for a directory entry ("Seulement 0225:, 0208: et
     * 9925: sont supportés"). ISO 6523 0002 (SIRENE, used below for the GlobalId
     * and LegalOrganisation identity fields) is a valid EN16931 identifier but is
     * not a routable Peppol address on their network — using it here silently
     * misroutes every submission.
     */
    private const string PEPPOL_FRANCE_SCHEME = '0225';

    public function __construct(
        private SystemConfig $systemConfig,
        private TaxIdentifierRepository $taxIdentifierRepository,
        private TaxCalculatorInterface $taxCalculator,
        private Environment $twig,
        private BillingTemplateResolver $templateResolver,
        private Generator $pdfGenerator,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function build(Invoice $invoice): string
    {
        $documentBuilder = $this->buildDocument($invoice);

        $pdfContent = $this->pdfGenerator->generate(
            $this->twig->render($this->templateResolver->resolve($invoice, BillingTemplateChannel::Pdf), ['invoice' => $invoice]),
            protect: false,
        );

        $pdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
        $pdfBuilder->setAdditionalCreatorTool('Augias');
        $pdfBuilder->generateDocument();

        return $pdfBuilder->downloadString();
    }

    /**
     * @throws JsonException
     */
    public function buildDocument(Invoice $invoice): ZugferdDocumentBuilder
    {
        $client = $invoice->getClient();

        $documentBuilder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $documentBuilder->setDocumentInformation(
            $invoice->getInvoiceId(),
            ZugferdInvoiceType::INVOICE,
            $invoice->getInvoiceDate(),
            $client?->getCurrencyCode() ?? $this->systemConfig->getCurrency()->getCode(),
        );

        // BT-23: mandatory "cadre de facturation" code, read off the lines. An
        // invoice of goods alone is "B1". Anything with a service stays "S1",
        // mixed included: "M1" would be the exact code, but SUPER PDP rejects
        // "M*" codes outright whenever it classifies the flow as B2BInt
        // (international), where only a goods-or-services code is accepted.
        $documentBuilder->setDocumentBusinessProcess($this->businessProcess($invoice));

        $this->addMandatoryFrenchNotes($documentBuilder);

        // The mention the law asks of a document issued under the option for
        // VAT on debits, in the data as well as on the page.
        if ($invoice->isVatOnDebits()) {
            $documentBuilder->addDocumentNote(Invoice::VAT_ON_DEBITS_MENTION);
        }

        // Without a delivery/supply date, zugferd still emits an empty
        // ApplicableHeaderTradeDelivery element, which PEPPOL-EN16931-R008 rejects.
        // The invoice date stands in for a supply date Augias doesn't track.
        $documentBuilder->setDocumentSupplyChainEvent($invoice->getInvoiceDate());

        $this->setSeller($documentBuilder, $invoice->getCompany());

        if ($client instanceof Client) {
            $this->setBuyer($documentBuilder, $client);
        }

        $result = $this->taxCalculator->calculate($invoice);

        /** @var list<Line> $lines */
        $lines = array_values($invoice->getLines()->toArray());

        /** @var array<string, array{category: TaxCategory, rate: ?float, basis: float, tax: float}> $vatGroups */
        $vatGroups = [];
        $lineTotal = 0.0;

        foreach ($lines as $index => $line) {
            $breakdown = $result->lineBreakdowns[$index] ?? null;

            // Disbursements are not on the invoice: they go out on a note of
            // their own. EN 16931 forbids a "not subject to VAT" breakdown next
            // to any other (BR-O-11), and a disbursement note is not an invoice,
            // so it never reaches the platform at all.
            if ($breakdown === null || $line->isDisbursement()) {
                continue;
            }

            $qty = $line->getQty()->toFloat();
            // LineBreakdown/TaxSummaryRow amounts are minor units (cents), like
            // Invoice::getTotal()/getBaseTotal() — must go through minorToFloat()
            // the same way, or every amount ends up 100x too large in the XML.
            $lineNet = $this->minorToFloat($breakdown->lineSubtotal);
            $unitPrice = $qty !== 0.0 ? $lineNet / $qty : $lineNet;
            $lineTotal += $lineNet;

            $documentBuilder->addNewPosition((string) $line->getId());
            $documentBuilder->setDocumentPositionProductDetails((string) $line->getDescription());
            $documentBuilder->setDocumentPositionNetPrice($unitPrice);
            $documentBuilder->setDocumentPositionQuantity($qty, ZugferdUnitCodes::REC20_ONE);

            $category = $this->lineVatCategory($line);
            $categoryCode = $this->mapVatCategory($category);
            /** @var TaxSummaryRow|null $taxRow */
            $taxRow = $breakdown->taxRows[0] ?? null;
            $rate = $this->categoryRate($category, $taxRow);
            $taxAmount = $taxRow !== null ? $this->minorToFloat($taxRow->amount) : 0.0;
            [$exemptionReason, $exemptionReasonCode] = $this->exemptionReasonFor($category);

            $documentBuilder->addDocumentPositionTax($categoryCode, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $rate, null, $exemptionReason, $exemptionReasonCode);
            $documentBuilder->setDocumentPositionLineSummation($lineNet);

            $groupKey = $categoryCode . '|' . ($rate ?? 'null');
            $vatGroups[$groupKey] ??= [
                'category' => $category,
                'rate' => $rate,
                'basis' => 0.0,
                'tax' => 0.0,
            ];
            $vatGroups[$groupKey]['basis'] += $lineNet;
            $vatGroups[$groupKey]['tax'] += $taxAmount;
        }

        foreach ($vatGroups as $group) {
            [$exemptionReason, $exemptionReasonCode] = $this->exemptionReasonFor($group['category']);

            $documentBuilder->addDocumentTax(
                $this->mapVatCategory($group['category']),
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                $group['basis'],
                $group['tax'],
                $group['rate'],
                $exemptionReason,
                $exemptionReasonCode,
                // BT-8: under the option for VAT on debits the tax falls due on
                // the invoice date — "5" in UNTDID 2475, the list CII uses.
                // Left out otherwise, which is the cash rule.
                dueDateTypeCode: $invoice->isVatOnDebits() ? self::VAT_DUE_ON_INVOICE_DATE : null,
            );
        }

        // BR-CO-10: the header's line-total must equal the sum of the individual
        // position totals set via setDocumentPositionLineSummation() above — using
        // Invoice::getBaseTotal() here instead risks diverging by rounding cents
        // from a total computed independently elsewhere in the app.
        // The fees alone — the figures the invoice document shows, the
        // disbursements being on the note.
        $documentBuilder->setDocumentSummation(
            $this->minorToFloat($invoice->getFeesTotal()),
            $this->minorToFloat($invoice->getFeesPayableAmount()),
            $lineTotal,
            null,
            $this->discountAmount($invoice),
            $lineTotal,
            $this->minorToFloat($invoice->getTax()),
        );

        return $documentBuilder;
    }

    private function setSeller(ZugferdDocumentBuilder $documentBuilder, Company $company): void
    {
        $name = $this->systemConfig->get('system/company/company_name') ?? $company->getName();

        $documentBuilder->setDocumentSeller($name);
        $this->setAddress($documentBuilder->setDocumentSellerAddress(...), $this->companyAddress());

        foreach ($this->taxIdentifierRepository->findCompanyIdentifiers($company->getId()) as $identifier) {
            if ($this->isFrenchCompanyNumber($identifier)) {
                $siret = $identifier->getValue();
                $globalSiren = $this->sirenForScheme0002($siret);

                if ($globalSiren !== null) {
                    $documentBuilder->addDocumentSellerGlobalId($globalSiren, ZugferdSchemeIdentifiers::ISO_6523_0002);
                    $documentBuilder->setDocumentSellerLegalOrganisation($globalSiren, ZugferdSchemeIdentifiers::ISO_6523_0002, null);
                }

                $documentBuilder->setDocumentSellerCommunication(self::PEPPOL_FRANCE_SCHEME, $this->siren($siret));

                continue;
            }

            if ($identifier->getLabel() === 'TVA intracommunautaire') {
                $documentBuilder->addDocumentSellerVATRegistrationNumber($identifier->getValue());
            }
        }
    }

    private function setBuyer(ZugferdDocumentBuilder $documentBuilder, Client $client): void
    {
        $documentBuilder->setDocumentBuyer((string) $client->getName());

        $address = null;

        foreach ($client->getAddresses() as $candidate) {
            if (! $candidate->isEmpty()) {
                $address = $candidate;

                break;
            }
        }

        $this->setAddress($documentBuilder->setDocumentBuyerAddress(...), $address);

        foreach ($client->getTaxIdentifiers() as $identifier) {
            if ($this->isFrenchCompanyNumber($identifier)) {
                $siret = $identifier->getValue();
                $globalSiren = $this->sirenForScheme0002($siret);

                if ($globalSiren !== null) {
                    $documentBuilder->addDocumentBuyerGlobalId($globalSiren, ZugferdSchemeIdentifiers::ISO_6523_0002);
                    $documentBuilder->setDocumentBuyerLegalOrganisation($globalSiren, ZugferdSchemeIdentifiers::ISO_6523_0002, null);
                }

                $documentBuilder->setDocumentBuyerCommunication(self::PEPPOL_FRANCE_SCHEME, $this->siren($siret));

                continue;
            }

            if ($identifier->getLabel() === 'TVA intracommunautaire') {
                $documentBuilder->addDocumentBuyerVATRegistrationNumber($identifier->getValue());
            }
        }
    }

    /**
     * BR-08/BR-09 (and their buyer equivalents BR-10/BR-11): an invoice must always
     * carry a seller/buyer postal address with at least a country code, even when
     * nothing else is configured — so, unlike the other optional fields, country
     * falls back to "FR" rather than being left out.
     *
     * @param callable(?string, ?string, ?string, ?string, ?string, ?string): mixed $setter
     */
    private function setAddress(callable $setter, ?Address $address): void
    {
        if ($address instanceof Address && $address->isEmpty()) {
            $address = null;
        }

        $setter(
            $address?->getStreet1(),
            $address?->getStreet2(),
            null,
            $address?->getZip(),
            $address?->getCity(),
            $address?->getCountry() ?? 'FR',
        );
    }

    /**
     * @throws JsonException
     */
    private function companyAddress(): ?Address
    {
        $raw = $this->systemConfig->get('system/company/contact_details/address');

        if ($raw === null || $raw === '') {
            return null;
        }

        /** @var array{street1: ?string, street2: ?string, city: ?string, state: ?string, zip: ?string, country: ?string} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return Address::fromArray($decoded);
    }

    /**
     * A French SIREN/SIRET feeds three different EN16931 fields: the party
     * identifier (BT-29/46, GlobalID) and the legal registration identifier
     * (BT-30/47, SpecifiedLegalOrganization) under ISO 6523 ICD "0002" (the
     * French SIRENE registry) unchanged, and the electronic/routing address
     * (BT-34/49) under scheme "0225" via {@see siren()} — SUPER PDP reports
     * each of these as missing independently if only some are set.
     */
    private function isFrenchCompanyNumber(TaxIdentifier $identifier): bool
    {
        return $identifier->getLabel() === 'SIRET' || $identifier->getLabel() === 'SIREN';
    }

    /**
     * A Peppol scheme-0225 address is keyed on the 9-digit SIREN, not the
     * 14-digit SIRET (SIREN + 5-digit NIC establishment code) — Augias's
     * "SIRET"/"SIREN" tax identifier labels don't distinguish the two, so the
     * SIREN is extracted only when the value is unambiguously a full SIRET
     * (14 numeric digits). Anything else — already a bare SIREN, a malformed
     * value, or a provider-specific non-numeric test address — is passed
     * through unchanged rather than blindly cut to 9 characters, since a
     * SIRET is the only shape this can reliably recognise.
     */
    private function siren(?string $identifierValue): ?string
    {
        if ($identifierValue === null) {
            return null;
        }

        return strlen($identifierValue) === 14 && ctype_digit($identifierValue)
            ? substr($identifierValue, 0, 9)
            : $identifierValue;
    }

    /**
     * BR-FR-32: any Party identifier under ISO 6523 scheme "0002" (the GlobalID
     * and SpecifiedLegalOrganization fields this feeds) must be composed of
     * exactly 9 digits — a full 14-digit SIRET fails this rule outright, so
     * unlike siren() (used for the routing address, which has no such
     * digit-count constraint) this always needs a 9-digit result or nothing:
     * a real SIRET's leading 9 digits, a bare 9-digit SIREN passed through, or
     * — for a compound identifier not shaped like either — its leading 9
     * characters only if they happen to be all-numeric. Anything else can't be
     * made compliant, so the field is left unset rather than submit a value
     * that fails schema validation.
     */
    private function sirenForScheme0002(?string $identifierValue): ?string
    {
        if ($identifierValue === null || strlen($identifierValue) < 9) {
            return null;
        }

        $candidate = substr($identifierValue, 0, 9);

        return ctype_digit($candidate) ? $candidate : null;
    }

    /**
     * BR-FR-05/BT-22: three legal mentions French law requires on every invoice
     * (recovery-fee indemnity, late-payment penalty rate, early-payment discount
     * policy — Code de commerce art. L441-10/D441-5). Augias doesn't let a
     * company customize this wording yet, so fixed boilerplate text is used.
     */
    private function addMandatoryFrenchNotes(ZugferdDocumentBuilder $documentBuilder): void
    {
        $documentBuilder->addDocumentNote(
            'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.',
            null,
            'PMT',
        );
        $documentBuilder->addDocumentNote(
            "Taux de pénalités de retard : trois fois le taux d'intérêt légal.",
            null,
            'PMD',
        );
        $documentBuilder->addDocumentNote(
            "Pas d'escompte pour paiement anticipé.",
            null,
            'AAB',
        );
    }

    /**
     * "B1" when every line sold is goods, "S1" otherwise. Disbursements sell
     * nothing and do not count either way.
     */
    private function businessProcess(Invoice $invoice): string
    {
        $goods = false;

        foreach ($invoice->getLines() as $line) {
            if ($line->isDisbursement()) {
                continue;
            }

            if ($line->getSupplyType() !== SupplyType::Goods) {
                return 'S1';
            }

            $goods = true;
        }

        return $goods ? 'B1' : 'S1';
    }

    /**
     * The line's own tax category, read directly from its LineTax entities rather
     * than the calculator's summary rows — the calculator silently skips Exempt
     * rows entirely (see LineTaxCalculator), which would otherwise make a
     * deliberately Exempt line indistinguishable from one with no tax at all.
     * A line with no tax at all defaults to ZeroRated: unlike OutOfScope/Exempt,
     * it doesn't require an exemption reason and doesn't forbid a seller/buyer VAT
     * number elsewhere on the invoice (BR-O-02), so it's the safer unknown-case default.
     */
    /**
     * Read from the line's own snapshot rather than the calculator, deliberately,
     * because LineTaxCalculator drops Exempt rows entirely.
     *
     * A line with no tax at all used to fall back to ZeroRated — code "Z", which
     * claims a 0% rate was applied. For a company in franchise en base that is
     * simply wrong: no rate applies, which is code "E". The fallback now follows
     * the company's VAT status.
     */
    private function lineVatCategory(Line $line): TaxCategory
    {
        $firstTax = $line->getTaxes()->first();

        if ($firstTax instanceof LineTax) {
            return $firstTax->getCategorySnapshot();
        }

        return $this->systemConfig->isVatExempt() ? TaxCategory::Exempt : TaxCategory::ZeroRated;
    }

    private function categoryRate(TaxCategory $category, ?TaxSummaryRow $taxRow): ?float
    {
        // BR-O-05 (and the equivalent rule for Exempt): a line whose VAT category is
        // "Not subject to VAT" or "Exempt" must not carry a VAT rate at all.
        if ($category === TaxCategory::Exempt || $category === TaxCategory::OutOfScope) {
            return null;
        }

        return $taxRow !== null ? (float) $taxRow->rate : 0.0;
    }

    /**
     * The configured exemption wording, falling back to a generic phrase for a
     * company that is liable overall but has an exempt line.
     */
    private function exemptionText(): string
    {
        return $this->systemConfig->isVatExempt()
            ? $this->systemConfig->vatExemptMention()
            : 'Exonération de TVA';
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function exemptionReasonFor(TaxCategory $category): array
    {
        return match ($category) {
            // BR-O-10: category "O" needs an exemption reason code or text.
            TaxCategory::OutOfScope => ['Non soumis à la TVA', ZugferdVATExemptionReasonCode::VATEX_EU_O],
            // The equivalent rule for "E": no single EU code fits every possible
            // national exemption basis, so free text is used instead of a code.
            // The company's own wording when it has one — for a French
            // micro-entreprise that is the article 293 B mention it is required
            // to carry, and the receiving platform should see the same reason
            // the printed invoice does.
            TaxCategory::Exempt => [$this->exemptionText(), null],
            default => [null, null],
        };
    }

    private function mapVatCategory(TaxCategory $category): string
    {
        return match ($category) {
            TaxCategory::Standard => ZugferdVatCategoryCodes::STAN_RATE,
            TaxCategory::ZeroRated => ZugferdVatCategoryCodes::ZERO_RATE_GOOD,
            TaxCategory::Exempt => ZugferdVatCategoryCodes::EXEM_FROM_TAX,
            TaxCategory::OutOfScope => ZugferdVatCategoryCodes::SERV_OUTS_SCOP_OF_TAX,
            TaxCategory::ReverseCharge => ZugferdVatCategoryCodes::VAT_REVE_CHAR,
        };
    }

    private function minorToFloat(BigNumber $minorUnits): float
    {
        return $minorUnits->toFloat() / 100;
    }

    private function discountAmount(Invoice $invoice): ?float
    {
        if (! $invoice->hasDiscount()) {
            return null;
        }

        $discount = $invoice->getDiscount();

        if ($discount->getType() === Discount::TYPE_MONEY) {
            return $this->minorToFloat($discount->getValueMoney());
        }

        $percentage = $discount->getValuePercentage() ?? 0.0;

        return $this->minorToFloat($invoice->getBaseTotal()) * ($percentage / 100);
    }
}
