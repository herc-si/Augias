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

namespace Augias\BillBundle\Tests\Import;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Import\BillImporter;
use Augias\BillBundle\Import\FacturXReader;
use Augias\BillBundle\Import\UnreadableInvoice;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\FacturXInvoiceBuilder;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * A supplier's Factur-X read back into a bill: the figures are the
 * supplier's own, nothing is guessed, and the file is kept.
 *
 * The invoice is one Augias issues itself — a Factur-X from a real
 * generator, round-tripped.
 */
#[CoversClass(FacturXReader::class)]
#[CoversClass(BillImporter::class)]
#[Group('functional')]
final class FacturXImportTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testAFacturXPdfBecomesADraftBillWithItsFigures(): void
    {
        $pdf = $this->facturX('FX-2026-001', 10000);

        $read = new FacturXReader()->read($pdf);
        self::assertNotNull($read);
        self::assertSame('FX-2026-001', $read->number);
        self::assertTrue($read->total->isEqualTo('100.00'));
        self::assertSame('EUR', $read->currency);

        $bill = self::getContainer()->get(BillImporter::class)->import($this->companyRef(), $pdf);

        self::assertSame(BillStatus::Draft, $bill->getStatus(), 'To be checked, as a bill typed in.');
        self::assertSame('FX-2026-001', $bill->getBillNumber());
        self::assertTrue($bill->getTotalAmount()->isEqualTo(10000), 'In cents.');
        self::assertSame('EUR', $bill->getCurrencyCode());
        self::assertTrue($bill->getSupplier()->isSupplier());
        self::assertSame('application/pdf', $bill->getDocumentMimeType());
        self::assertStringStartsWith('var/bills/', (string) $bill->getDocumentPath());
    }

    public function testTheXmlOnItsOwnIsReadToo(): void
    {
        $xml = self::getContainer()->get(FacturXInvoiceBuilder::class)->buildDocument($this->issuedInvoice('FX-2026-002', 4990))->getContent();

        $read = new FacturXReader()->read($xml);

        self::assertNotNull($read);
        self::assertSame('FX-2026-002', $read->number);
        self::assertTrue($read->total->isEqualTo('49.90'));
    }

    public function testAPlainPdfIsLeftToBeTypedIn(): void
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML('<p>Facture papier scannée</p>');
        $plain = $mpdf->Output('', 'S');

        self::assertNull(new FacturXReader()->read($plain));
        self::assertNull(new FacturXReader()->read('not a file at all'));

        $this->expectException(UnreadableInvoice::class);
        self::getContainer()->get(BillImporter::class)->import($this->companyRef(), $plain);
    }

    /**
     * Through the page: the file dropped on the new bill form opens the
     * draft, and the fiche links back to the supplier's document.
     */
    public function testThroughThePage(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($file, $this->facturX('FX-2026-003', 2500));
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/bills/add')
            ->assertSuccessful()
            ->attachFile('invoice', $file)
            ->click('Import')
            ->assertSuccessful()
            ->assertSee('Invoice imported from its Factur-X');

        $bill = $this->em->getRepository(Bill::class)->findOneBy(['billNumber' => 'FX-2026-003']);
        self::assertInstanceOf(Bill::class, $bill);

        $browser->visit('/bills/view/' . $bill->getId())
            ->assertSeeElement('a[href="/bills/document/' . $bill->getId() . '"]')
            ->visit('/bills/document/' . $bill->getId())
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/pdf');
    }

    private function facturX(string $number, int $cents): string
    {
        return self::getContainer()->get(FacturXInvoiceBuilder::class)->build($this->issuedInvoice($number, $cents));
    }

    private function issuedInvoice(string $number, int $cents): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'name' => 'Buyer ' . $number]);

        $invoice = new Invoice();
        $invoice->setCompany($this->companyRef());
        $invoice->setClient($client);
        $invoice->setInvoiceId($number);
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line()->setDescription('Consulting services')->setPrice($cents)->setQty(1);
        $line->updateTotal();
        $invoice->addLine($line);

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    private function companyRef(): Company
    {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
