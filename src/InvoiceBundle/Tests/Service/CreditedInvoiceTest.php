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

namespace Augias\InvoiceBundle\Tests\Service;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Listener\Workflow\OffsetCreditedInvoiceListener;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Augias\InvoiceBundle\Service\InvoiceSettlement;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use function assert;

/**
 * A credit note set against an invoice is what the client no longer owes on
 * it. It used to come off the client's credit and nowhere else: the invoice
 * kept its full balance, went overdue and was chased.
 */
#[CoversClass(InvoiceSettlement::class)]
#[CoversClass(OffsetCreditedInvoiceListener::class)]
final class CreditedInvoiceTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private ?Client $testClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testACancellingCreditNoteCreditsTheUnpaidInvoiceItAnswers(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending, 10_000);

        $creditNote = $this->issuedCreditNote(10_000, $invoice);

        self::assertSame(InvoiceStatus::Credited, $invoice->getStatus());
        self::assertTrue($invoice->getBalance()->isZero());
        self::assertSame(CreditNoteStatus::Settled, $creditNote->getStatus());
        self::assertSame('0', (string) $creditNote->getClient()->getCredit()->getValue(), 'Nothing is left to the client: the credit went to the invoice.');
    }

    public function testAnOverdueInvoiceIsCreditedToo(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Overdue, 10_000);

        $this->issuedCreditNote(10_000, $invoice);

        self::assertSame(InvoiceStatus::Credited, $invoice->getStatus());
    }

    public function testAPartialCreditLowersWhatIsOwed(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending, 10_000);

        $this->issuedCreditNote(3_000, $invoice);

        self::assertSame(InvoiceStatus::Pending, $invoice->getStatus());
        self::assertSame('7000', (string) $invoice->getBalance()->toBigInteger());
    }

    public function testACreditLargerThanWhatIsOwedLeavesTheRestToTheClient(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending, 10_000);

        $creditNote = $this->issuedCreditNote(15_000, $invoice);

        self::assertSame(InvoiceStatus::Credited, $invoice->getStatus());
        self::assertSame(CreditNoteStatus::Issued, $creditNote->getStatus());
        self::assertSame('5000', (string) $creditNote->getClient()->getCredit()->getValue());
    }

    /**
     * A paid invoice owes nothing: the credit is money to give back or to
     * deduct later, which is the user's call.
     */
    public function testAPaidInvoiceIsLeftAlone(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Paid, 10_000);

        $creditNote = $this->issuedCreditNote(10_000, $invoice);

        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame(CreditNoteStatus::Issued, $creditNote->getStatus());
        self::assertSame('10000', (string) $creditNote->getClient()->getCredit()->getValue());
    }

    public function testSettingACreditNoteAgainstAnotherInvoiceCreditsItOnceNothingIsOwed(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);
        $invoice = $this->invoice(InvoiceStatus::Pending, 10_000);

        $allocator = self::getContainer()->get(CreditNoteAllocator::class);
        assert($allocator instanceof CreditNoteAllocator);
        $allocator->allocate($creditNote, AllocationKind::Offset, 10_000, $invoice);

        self::assertSame(InvoiceStatus::Credited, $invoice->getStatus());
    }

    /**
     * Part credited is not settled: the rest is still owed, and still chased.
     */
    public function testAPartlyCreditedInvoiceStillAwaitsTheRest(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending, 10_000);
        $this->issuedCreditNote(3_000, $invoice);

        $repository = $this->entityManager->getRepository(Invoice::class);
        assert($repository instanceof InvoiceRepository);

        self::assertFalse($repository->isFullyPaid($invoice));
    }

    private function invoice(InvoiceStatus $status, int $total): Invoice
    {
        $invoice = InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => $status,
            'discount' => new Discount(),
            'lines' => [
                new Line()
                    ->setDescription('Service')
                    ->setPrice($total)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $invoice->setBalance(InvoiceStatus::Paid === $status ? 0 : $total);
        $this->entityManager->flush();

        return $invoice;
    }

    private function issuedCreditNote(int $total, ?Invoice $creditedInvoice = null): CreditNote
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Draft,
            'creditedInvoice' => $creditedInvoice,
            'discount' => new Discount(),
            'lines' => [
                new CreditNoteLine()
                    ->setDescription('Credited')
                    ->setPrice($total)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $registry = self::getContainer()->get(Registry::class);
        assert($registry instanceof Registry);
        $registry->get($creditNote, 'credit_note')->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
        $this->entityManager->flush();

        return $creditNote;
    }

    private function client(): Client
    {
        return $this->testClient ??= ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
    }
}
