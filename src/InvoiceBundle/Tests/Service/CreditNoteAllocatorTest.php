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
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;
use function assert;

#[CoversClass(CreditNoteAllocator::class)]
final class CreditNoteAllocatorTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private CreditNoteAllocator $allocator;

    private EntityManagerInterface $entityManager;

    private WorkflowInterface $workflow;

    private ?Client $testClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        $allocator = self::getContainer()->get(CreditNoteAllocator::class);
        assert($allocator instanceof CreditNoteAllocator);
        $this->allocator = $allocator;

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $registry = self::getContainer()->get(Registry::class);
        $this->workflow = $registry->get(new CreditNote(), 'credit_note');
    }

    /**
     * Issuing is what makes the client owed, so it is what the balance has to
     * show — before a single allocation exists.
     */
    public function testIssuingPutsTheAmountOnTheClientBalance(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        self::assertSame('10000', (string) $creditNote->getClient()->getCredit()->getValue());
    }

    public function testSettingACreditAgainstAnInvoiceRecordsItAndTakesItOffTheBalance(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $this->client()]);

        $allocation = $this->allocator->allocate($creditNote, AllocationKind::Offset, 4_000, $invoice);

        self::assertSame('4000', (string) $allocation->getAmount());
        self::assertSame($invoice->getId(), $allocation->getInvoice()?->getId());
        self::assertSame('6000', (string) $creditNote->getClient()->getCredit()->getValue());
        self::assertSame('6000', (string) $this->allocator->remaining($creditNote));
    }

    public function testARefundRecordsWithNoInvoice(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        $allocation = $this->allocator->allocate($creditNote, AllocationKind::Refund, 10_000);

        self::assertNull($allocation->getInvoice());
        self::assertTrue($allocation->getKind()->movesMoney());
    }

    public function testUsingItUpSettlesIt(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        $this->allocator->allocate($creditNote, AllocationKind::Refund, 10_000);

        self::assertSame(CreditNoteStatus::Settled, $creditNote->getStatus());
        self::assertSame('0', (string) $this->allocator->remaining($creditNote));
    }

    /**
     * A credit note used up in several goes stays owed until the last one.
     */
    public function testUsingPartOfItLeavesItOpen(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $this->client()]);

        $this->allocator->allocate($creditNote, AllocationKind::Offset, 3_000, $invoice);
        $this->allocator->allocate($creditNote, AllocationKind::Refund, 2_000);

        self::assertSame(CreditNoteStatus::Issued, $creditNote->getStatus());
        self::assertSame('5000', (string) $this->allocator->remaining($creditNote));
        self::assertCount(2, $creditNote->getAllocations());
    }

    public function testRefusesToGiveBackMoreThanIsOwed(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/only 10000 left/');

        $this->allocator->allocate($creditNote, AllocationKind::Refund, 10_001);
    }

    public function testRefusesToAllocateADraft(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Draft,
            'discount' => new Discount(),
            'lines' => [
                new CreditNoteLine()
                    ->setDescription('Credited')
                    ->setPrice(10_000)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/has not been issued/');

        $this->allocator->allocate($creditNote, AllocationKind::Refund, 1_000);
    }

    public function testRefusesANonPositiveAmount(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/positive amount/');

        $this->allocator->allocate($creditNote, AllocationKind::Refund, 0);
    }

    public function testRefusesAnOffsetWithNoInvoice(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/needs the invoice/');

        $this->allocator->allocate($creditNote, AllocationKind::Offset, 1_000);
    }

    public function testRefusesARefundPointedAtAnInvoice(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $this->client()]);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/money leaving/');

        $this->allocator->allocate($creditNote, AllocationKind::Refund, 1_000, $invoice);
    }

    /**
     * Credit belongs to the client it was raised for. Setting it against
     * someone else's invoice would move money between accounts with nothing to
     * show for it.
     */
    public function testRefusesToCreditAnotherClientsInvoice(): void
    {
        $creditNote = $this->issuedCreditNote(10_000);
        $other = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $other]);

        $this->expectException(AllocationException::class);
        $this->expectExceptionMessageMatches('/only be set against invoices of the client/');

        $this->allocator->allocate($creditNote, AllocationKind::Offset, 1_000, $invoice);
    }

    /**
     * The total comes from a line rather than from the attribute: the save
     * listener recalculates it on every flush, so a credit note with no lines
     * is worth nothing however it was created.
     */
    private function issuedCreditNote(int $total): CreditNote
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Draft,
            'discount' => new Discount(),
            'lines' => [
                new CreditNoteLine()
                    ->setDescription('Credited')
                    ->setPrice($total)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $this->workflow->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
        $this->entityManager->flush();

        return $creditNote;
    }

    private function client(): Client
    {
        return $this->testClient ??= ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
    }
}
