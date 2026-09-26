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

namespace Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\FacturXInvoiceBuilder;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function json_encode;
use function substr_count;

#[CoversClass(FacturXInvoiceBuilder::class)]
final class FacturXInvoiceBuilderTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testBuildDocumentProducesAnEn16931XmlWithSellerBuyerAndLines(): void
    {
        self::getContainer()->get(SystemConfig::class)->set('system/company/company_name', 'Acme Corp');
        self::getContainer()->get(SystemConfig::class)->set('system/company/contact_details/address', (string) json_encode([
            'street1' => '1 Rue de la Paix',
            'street2' => null,
            'city' => 'Paris',
            'state' => null,
            'zip' => '75002',
            'country' => 'FR',
        ]));

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => null,
            'label' => 'SIRET',
            'value' => '11111111100011',
        ]);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => '22222222200022',
        ]);

        $address = new Address();
        $address->setStreet1('10 Rue du Client')->setCity('Lyon')->setZip('69001')->setCountry('FR');
        $client->addAddress($address);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-0001');
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line();
        $line->setDescription('Consulting services');
        $line->setPrice(10000);
        $line->setQty(2);
        $line->updateTotal();

        $lineTax = new LineTax();
        $lineTax->setNameSnapshot('VAT');
        $lineTax->setRateSnapshot('20.0000');
        $line->addTax($lineTax);

        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $documentBuilder = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice);
        $xml = $documentBuilder->getContent();

        self::assertStringContainsString('INV-0001', $xml);
        self::assertStringContainsString('Acme Corp', $xml);
        self::assertStringContainsString('Consulting services', $xml);
        self::assertStringContainsString('EUR', $xml);

        // The SIRET must be a scheme-qualified GlobalID (ISO 6523 ICD "0002", the
        // French SIRENE registry) — not a plain tax-registration number — otherwise
        // SUPER PDP cannot compute the processing_rule (B2B/B2C/B2G) from the document.
        // BR-FR-32 requires exactly 9 digits under this scheme, so it's the SIREN
        // (the 14-digit SIRET's leading 9 digits), not the full SIRET.
        self::assertMatchesRegularExpression(
            '/<ram:GlobalID schemeID="0002">111111111<\/ram:GlobalID>/',
            $xml,
        );
        self::assertMatchesRegularExpression(
            '/<ram:GlobalID schemeID="0002">222222222<\/ram:GlobalID>/',
            $xml,
        );

        // BT-34 Seller electronic address: this is the actual Peppol routing/delivery
        // address, not just an identity field — SUPER PDP's directory only resolves
        // French recipients under scheme "0225", keyed on the 9-digit SIREN (the
        // SIRET's first 9 digits), never "0002" (SIRENE is a valid EN16931 identity
        // scheme but isn't a routable address on their network).
        self::assertMatchesRegularExpression(
            '/<ram:URIID schemeID="0225">111111111<\/ram:URIID>/',
            $xml,
        );
        self::assertMatchesRegularExpression(
            '/<ram:URIID schemeID="0225">222222222<\/ram:URIID>/',
            $xml,
        );

        // BT-30 Seller legal registration identifier: a separate field from GlobalID
        // and the electronic address — SUPER PDP reports it missing independently.
        self::assertMatchesRegularExpression(
            '/<ram:SpecifiedLegalOrganization>\s*<ram:ID schemeID="0002">111111111<\/ram:ID>/',
            $xml,
        );

        // BT-23 mandatory "cadre de facturation" code (BR-FR-08).
        self::assertStringContainsString('<ram:ID>S1</ram:ID>', $xml);

        // BR-FR-05 mandatory French legal mentions (recovery fees, late-payment
        // penalties, early-payment discount policy).
        self::assertStringContainsString('<ram:SubjectCode>PMT</ram:SubjectCode>', $xml);
        self::assertStringContainsString('<ram:SubjectCode>PMD</ram:SubjectCode>', $xml);
        self::assertStringContainsString('<ram:SubjectCode>AAB</ram:SubjectCode>', $xml);

        // A delivery/supply date, so ApplicableHeaderTradeDelivery isn't left empty
        // (PEPPOL-EN16931-R008).
        self::assertStringContainsString('ActualDeliverySupplyChainEvent', $xml);

        // Seller and buyer postal addresses, each with a country code (BR-08/09/10/11).
        self::assertMatchesRegularExpression('/<ram:PostalTradeAddress>.*?<ram:CountryID>FR<\/ram:CountryID>/s', $xml);

        // Amounts are minor units (cents) on Line/Invoice — price 10000 (=100.00 EUR)
        // x qty 2 must come out as 200.00 EUR, not 20000.00 (100x too large).
        self::assertStringContainsString('<ram:ChargeAmount>100.00</ram:ChargeAmount>', $xml);
        self::assertStringContainsString('<ram:LineTotalAmount>200.00</ram:LineTotalAmount>', $xml);
        self::assertStringContainsString('<ram:TaxBasisTotalAmount>200.00</ram:TaxBasisTotalAmount>', $xml);
    }

    /**
     * Disbursements are not on the e-invoice: they go out on a note of their
     * own. EN 16931 forbids a "not subject to VAT" breakdown beside any other
     * (BR-O-11), so an invoice carrying fees and a disbursement together would
     * be rejected; the note is not an invoice and never reaches the platform.
     * What is left is the fees, and the totals are the fees' totals.
     */
    public function testTheEInvoiceCarriesTheFeesAndLeavesTheDisbursementsToTheirNote(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-DEB-1');
        $invoice->setStatus(InvoiceStatus::Draft);

        $fees = new Line();
        $fees->setDescription('Consulting services')->setPrice(40000)->setQty(1)->updateTotal();
        $vat = new LineTax();
        $vat->setNameSnapshot('VAT');
        $vat->setRateSnapshot('20.0000');
        $fees->addTax($vat);
        $invoice->addLine($fees);

        $screen = new Line();
        $screen->setDescription('Screen bought for the client')
            ->setPrice(50000)
            ->setQty(1)
            ->setDisbursement(true)
            ->updateTotal();
        $invoice->addLine($screen);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        self::assertStringNotContainsString('Screen bought for the client', $xml);
        self::assertStringNotContainsString('<ram:CategoryCode>O</ram:CategoryCode>', $xml, 'A single breakdown, the fees\' one.');
        self::assertStringContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);

        // 400 of fees and 80 of tax. The client still owes the 500 advanced,
        // but on the note, not on this invoice.
        self::assertStringContainsString('<ram:LineTotalAmount>400.00</ram:LineTotalAmount>', $xml);
        self::assertStringContainsString('<ram:TaxTotalAmount currencyID="EUR">80.00</ram:TaxTotalAmount>', $xml);
        self::assertStringContainsString('<ram:GrandTotalAmount>480.00</ram:GrandTotalAmount>', $xml);
        self::assertStringContainsString('<ram:DuePayableAmount>480.00</ram:DuePayableAmount>', $xml);
    }

    /**
     * BT-23 follows the lines: goods alone are "B1". A single service keeps
     * the invoice on "S1" — "M1" would be exact for a mixed one, but SUPER PDP
     * refuses it on an international flow.
     */
    public function testTheBillingFrameworkFollowsWhatTheLinesSell(): void
    {
        self::assertStringContainsString('<ram:ID>B1</ram:ID>', $this->xmlFor(SupplyType::Goods));
        self::assertStringContainsString('<ram:ID>S1</ram:ID>', $this->xmlFor(SupplyType::Goods, SupplyType::Services));
    }

    /**
     * Under the option for VAT on debits the e-invoice says so twice: BT-8
     * set to the invoice date ("5"), and the legal mention as a note. Neither
     * appears on an invoice under the cash rule.
     */
    public function testAnInvoiceOnDebitsSaysSoInTheData(): void
    {
        $onDebits = $this->xmlOf(true, SupplyType::Services);

        self::assertStringContainsString('<ram:DueDateTypeCode>5</ram:DueDateTypeCode>', $onDebits);
        self::assertStringContainsString(Invoice::VAT_ON_DEBITS_MENTION, $onDebits);

        $onReceipts = $this->xmlFor(SupplyType::Services);

        self::assertStringNotContainsString('DueDateTypeCode', $onReceipts);
        self::assertStringNotContainsString(Invoice::VAT_ON_DEBITS_MENTION, $onReceipts);
    }

    /**
     * BT-72 carries the delivery date when the invoice gives one, and falls
     * back to the invoice date otherwise.
     */
    public function testTheDeliveryDateIsSentAsTheSupplyDate(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-BT72');
        $invoice->setStatus(InvoiceStatus::Draft);
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-03-01'));
        $invoice->setDeliveryDate(new DateTimeImmutable('2026-05-15'));

        $line = new Line();
        $line->setDescription('Screens')->setPrice(10000)->setQty(1)->setSupplyType(SupplyType::Goods)->updateTotal();
        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        self::assertMatchesRegularExpression(
            '/<ram:ActualDeliverySupplyChainEvent>\s*<ram:OccurrenceDateTime>\s*<udt:DateTimeString format="102">20260515<\/udt:DateTimeString>/',
            $xml,
        );
    }

    /**
     * A company in franchise en base: every line on the invoice, in category
     * E, with the article 293 B mention as the exemption reason. From
     * 07/09/2026 the lines were all missing, and the platform rejected every
     * such invoice.
     */
    public function testAnInvoiceInFranchiseCarriesItsLinesAsExempt(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');
        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => null,
            'label' => 'SIRET',
            'value' => '11111111100011',
        ]);

        $xml = $this->xmlOf(false, SupplyType::Services, SupplyType::Goods);

        self::assertSame(2, substr_count($xml, '<ram:IncludedSupplyChainTradeLineItem>'));
        self::assertStringContainsString('<ram:CategoryCode>E</ram:CategoryCode>', $xml);
        self::assertStringContainsString('293 B', $xml);
        self::assertStringContainsString('<ram:GrandTotalAmount>200.00</ram:GrandTotalAmount>', $xml);
        // What the SUPER PDP validator asked for on 25/09/2026: a zero rate
        // for category E (BR-E-05), the SIRET as tax registration when there
        // is no VAT number (BR-E-02), and the French franchise code.
        self::assertStringContainsString('<ram:RateApplicablePercent>0.00</ram:RateApplicablePercent>', $xml);
        self::assertMatchesRegularExpression('/<ram:SpecifiedTaxRegistration>\s*<ram:ID schemeID="FC">\d+<\/ram:ID>/', $xml);
        self::assertStringContainsString('<ram:ExemptionReasonCode>VATEX-FR-FRANCHISE</ram:ExemptionReasonCode>', $xml);
    }

    /**
     * A registered address with a suffix — SUPER PDP's sandbox gives every
     * company one — has to be where answers are sent, not the bare SIREN.
     */
    public function testAnExplicitElectronicAddressIsWhereTheInvoiceIsDelivered(): void
    {
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'SIRET', 'value' => '00000000100011']);
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => null, 'label' => 'Adresse électronique', 'value' => '0225:315143296_92568']);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => $client, 'label' => 'SIRET', 'value' => '00000000200011']);
        TaxIdentifierFactory::createOne(['company' => $this->company, 'client' => $client, 'label' => 'Adresse électronique', 'value' => '315143296_92569']);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-ADDR');
        $invoice->setStatus(InvoiceStatus::Draft);
        $line = new Line();
        $line->setDescription('Service')->setPrice(10000)->setQty(1)->updateTotal();
        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        self::assertMatchesRegularExpression('/<ram:SellerTradeParty>.*<ram:URIID schemeID="0225">315143296_92568<\/ram:URIID>.*<\/ram:SellerTradeParty>/s', $xml);
        self::assertMatchesRegularExpression('/<ram:BuyerTradeParty>.*<ram:URIID schemeID="0225">315143296_92569<\/ram:URIID>.*<\/ram:BuyerTradeParty>/s', $xml);
        // The legal identity still comes from the SIRET.
        self::assertMatchesRegularExpression('/<ram:SpecifiedLegalOrganization>\s*<ram:ID schemeID="0002">000000001<\/ram:ID>/', $xml);
    }

    /**
     * EN 16931 has no VAT on the invoice as a whole. A rate set on the
     * document goes on every line that has none of its own, with its share of
     * the tax, and the breakdown adds up to the total. The lines used to say
     * 0 % beside a total with the tax in it, which the platform refuses.
     */
    public function testAVatRateOnTheWholeInvoiceGoesOnEachLine(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-DOC-VAT');
        $invoice->setStatus(InvoiceStatus::Draft);

        foreach ([10000, 5000, 3333] as $price) {
            $line = new Line();
            $line->setDescription('Service ' . $price)->setPrice($price)->setQty(1)->updateTotal();
            $invoice->addLine($line);
        }

        $invoice->addInvoiceTax(new InvoiceTax()->setNameSnapshot('TVA')->setRateSnapshot('20')->setTypeSnapshot(TaxType::Exclusive)->setCategorySnapshot(TaxCategory::Standard));

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        self::assertStringNotContainsString('<ram:CategoryCode>Z</ram:CategoryCode>', $xml);
        self::assertSame(4, substr_count($xml, '<ram:CategoryCode>S</ram:CategoryCode>'), 'Three lines and one breakdown, all standard-rated.');
        self::assertSame(4, substr_count($xml, '<ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>'));
        // 183.33 net, 36.67 of tax (36.666 rounded), on the breakdown and in total.
        self::assertStringContainsString('<ram:BasisAmount>183.33</ram:BasisAmount>', $xml);
        self::assertStringContainsString('<ram:CalculatedAmount>36.67</ram:CalculatedAmount>', $xml);
        self::assertStringContainsString('<ram:TaxTotalAmount currencyID="EUR">36.67</ram:TaxTotalAmount>', $xml);
        self::assertStringContainsString('<ram:GrandTotalAmount>220.00</ram:GrandTotalAmount>', $xml);
    }

    private function xmlFor(SupplyType ...$types): string
    {
        return $this->xmlOf(false, ...$types);
    }

    private function xmlOf(bool $onDebits, SupplyType ...$types): string
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-BT23-' . count($types) . ($onDebits ? '-D' : ''));
        $invoice->setStatus(InvoiceStatus::Draft);
        $invoice->setVatOnDebits($onDebits);

        foreach ($types as $type) {
            $line = new Line();
            $line->setDescription($type->value)->setPrice(10000)->setQty(1)->setSupplyType($type)->updateTotal();
            $vat = new LineTax();
            $vat->setNameSnapshot('VAT');
            $vat->setRateSnapshot('20.0000');
            $line->addTax($vat);
            $invoice->addLine($line);
        }

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        return self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();
    }

    /**
     * SUPER PDP's own sandbox test addresses (e.g. "315143296_92569") aren't
     * 14-digit SIRETs — buildDocument() must pass a value like that through to
     * the routing address unchanged rather than truncating it to 9 characters,
     * since only an unambiguous 14-digit numeric SIRET should be shortened to
     * its SIREN.
     */
    public function testBuyerElectronicAddressIsNotTruncatedWhenTheIdentifierIsNotAFourteenDigitSiret(): void
    {
        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => null,
            'label' => 'SIRET',
            'value' => '11111111100011',
        ]);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => '315143296_92569',
        ]);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-0003');
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line();
        $line->setDescription('Consulting services');
        $line->setPrice(10000);
        $line->setQty(1);
        $line->updateTotal();
        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        // BT-49 electronic address: the full value, untouched.
        self::assertMatchesRegularExpression(
            '/<ram:URIID schemeID="0225">315143296_92569<\/ram:URIID>/',
            $xml,
        );

        // BT-46/BT-47 GlobalID/LegalOrganisation: BR-FR-32 requires exactly 9
        // digits under scheme 0002 — derived here from the identifier's
        // all-numeric leading 9 characters, not the full compound value.
        self::assertMatchesRegularExpression(
            '/<ram:GlobalID schemeID="0002">315143296<\/ram:GlobalID>/',
            $xml,
        );
        self::assertMatchesRegularExpression(
            '/<ram:SpecifiedLegalOrganization>\s*<ram:ID schemeID="0002">315143296<\/ram:ID>/',
            $xml,
        );
    }

    /**
     * BR-FR-32 (scheme 0002 identifiers must be exactly 9 digits) can't be
     * satisfied by an identifier with no 9-digit numeric prefix at all — the
     * field must be omitted rather than emit a value that would fail schema
     * validation, even though the routing address (BT-49, which has no such
     * digit-count rule) still gets the raw value.
     */
    public function testGlobalIdIsOmittedWhenNoCompliantSirenCanBeDerived(): void
    {
        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => null,
            'label' => 'SIRET',
            'value' => '11111111100011',
        ]);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => 'not-a-siren-at-all',
        ]);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-0004');
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line();
        $line->setDescription('Consulting services');
        $line->setPrice(10000);
        $line->setQty(1);
        $line->updateTotal();
        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($invoice)->getContent();

        // BT-49 electronic address still gets the raw value — no digit-count rule applies here.
        self::assertMatchesRegularExpression(
            '/<ram:URIID schemeID="0225">not-a-siren-at-all<\/ram:URIID>/',
            $xml,
        );

        // The value appears exactly once — as that electronic address — never as a
        // scheme-0002 GlobalID or SpecifiedLegalOrganization identifier.
        self::assertSame(1, substr_count($xml, 'not-a-siren-at-all'));
    }

    public function testBuildProducesAFacturXPdfWithTheXmlEmbedded(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => '22222222200022',
        ]);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-0002');
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line();
        $line->setDescription('Consulting services');
        $line->setPrice(10000);
        $line->setQty(1);
        $line->updateTotal();
        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        $pdf = self::getContainer()->get(FacturXInvoiceBuilder::class)->build($invoice);

        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
