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

namespace Augias\BillBundle\Tests\Functional;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillPaymentMethod;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Exception\InvalidTransitionException;
use Augias\BillBundle\Manager\BillManager;
use Augias\BillBundle\Manager\BillPaymentManager;
use Augias\BillBundle\Model\Graph;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

#[CoversClass(Bill::class)]
#[CoversClass(BillManager::class)]
#[CoversClass(BillPaymentManager::class)]
final class BillWorkflowTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testManuallyEnteredBillStartsAsDraftAndMovesThroughPaymentToPaid(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $supplier = new Client();
        $supplier->setCompany($this->company)->setName('Acme Supplies')->setIsClient(false)->setIsSupplier(true);
        $entityManager->persist($supplier);

        $bill = new Bill();
        $bill->setCompany($this->company)
            ->setSupplier($supplier)
            ->setBillNumber('SUP-0042')
            ->setIssueDate(new DateTimeImmutable('2026-01-01'))
            ->setDueDate(new DateTimeImmutable('2026-01-31'))
            ->setTotalAmount(BigInteger::of(10000))
            ->setCurrencyCode('EUR');
        $entityManager->persist($bill);
        $entityManager->flush();

        self::assertSame(BillStatus::Draft, $bill->getStatus());

        /** @var WorkflowInterface $stateMachine */
        $stateMachine = self::getContainer()->get('state_machine.bill');
        self::assertTrue($stateMachine->can($bill, Graph::TRANSITION_CONFIRM));
        $stateMachine->apply($bill, Graph::TRANSITION_CONFIRM);
        $entityManager->flush();

        self::assertSame(BillStatus::Pending, $bill->getStatus());

        $paymentManager = self::getContainer()->get(BillPaymentManager::class);

        $paymentManager->recordPayment(
            $bill,
            BigInteger::of(4000),
            new DateTimeImmutable('2026-01-10'),
            BillPaymentMethod::BankTransfer,
            'VIR-001',
        );

        self::assertSame(BillStatus::Pending->value, $bill->getStatus()->value);
        self::assertSame('6000', $bill->getBalance()->getAmount());

        $paymentManager->recordPayment(
            $bill,
            BigInteger::of(6000),
            new DateTimeImmutable('2026-01-20'),
            BillPaymentMethod::BankTransfer,
            'VIR-002',
        );

        self::assertSame(BillStatus::Paid, $bill->getStatus());
        self::assertTrue($bill->getBalance()->isZero());
        self::assertCount(2, $bill->getPayments());

        $repository = self::getContainer()->get(BillRepository::class);
        $persisted = $repository->find($bill->getId());
        self::assertNotNull($persisted);
        self::assertSame(BillStatus::Paid, $persisted->getStatus());
    }

    /**
     * A bill is not late on the day it falls due — it is late the day after.
     *
     * The query behind this bound its date as a datetime against a DATE column,
     * which on SQLite made today's bills overdue from midnight. Nothing showed
     * it, because nothing calls the method: there is a MarkOverdueInvoicesCommand
     * and no equivalent for bills. This is the spec it never had.
     */
    public function testABillIsOverdueOnlyOnceItsDueDateHasPassed(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $supplier = new Client();
        $supplier->setCompany($this->company)->setName('Acme Supplies')->setIsClient(false)->setIsSupplier(true);
        $entityManager->persist($supplier);

        $dueToday = $this->pendingBill($supplier, new DateTimeImmutable('today'));
        $dueYesterday = $this->pendingBill($supplier, new DateTimeImmutable('yesterday'));
        $entityManager->flush();

        $overdue = self::getContainer()->get(BillRepository::class)->getPendingOverdueBills();

        self::assertCount(1, $overdue);
        self::assertSame($dueYesterday->getId(), $overdue[0]->getId());
        self::assertNotSame($dueToday->getId(), $overdue[0]->getId());
    }

    private function pendingBill(Client $supplier, DateTimeImmutable $dueDate): Bill
    {
        $bill = new Bill();
        $bill->setCompany($this->company)
            ->setSupplier($supplier)
            ->setBillNumber('SUP-' . $dueDate->format('Ymd'))
            ->setIssueDate($dueDate->modify('-1 month'))
            ->setDueDate($dueDate)
            ->setTotalAmount(BigInteger::of(10000))
            ->setCurrencyCode('EUR')
            ->setStatus(BillStatus::Pending);

        self::getContainer()->get('doctrine')->getManager()->persist($bill);

        return $bill;
    }

    public function testConfirmingAnAlreadyPendingBillIsRejected(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $supplier = new Client();
        $supplier->setCompany($this->company)->setName('Acme Supplies')->setIsClient(false)->setIsSupplier(true);
        $entityManager->persist($supplier);

        $bill = new Bill();
        $bill->setCompany($this->company)
            ->setSupplier($supplier)
            ->setStatus(BillStatus::Pending)
            ->setTotalAmount(BigInteger::of(1000))
            ->setCurrencyCode('EUR');
        $entityManager->persist($bill);
        $entityManager->flush();

        /** @var WorkflowInterface $stateMachine */
        $stateMachine = self::getContainer()->get('state_machine.bill');

        self::assertFalse($stateMachine->can($bill, Graph::TRANSITION_CONFIRM));
    }

    public function testCreateFromReceiptStartsPendingAndResolvesTheSupplierByTaxIdentifier(): void
    {
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        $entityManager = $registry->getManager();

        $existingSupplier = new Client();
        $existingSupplier->setCompany($this->company)
            ->setName('Acme Supplies')
            ->setIsClient(false)
            ->setIsSupplier(true);
        $identifier = new TaxIdentifier();
        $identifier->setCompany($this->company)->setLabel('Tax ID')->setValue('111222333');
        $existingSupplier->addTaxIdentifier($identifier);
        $entityManager->persist($existingSupplier);
        $entityManager->flush();

        $receipt = new ElectronicInvoiceReceipt();
        $receipt->setCompany($this->company)
            ->setProvider('super_pdp')
            ->setExternalReference('555')
            ->setInvoiceNumber('SUP-0099')
            ->setSellerName('Acme Supplies')
            ->setSellerIdentifier('111222333')
            ->setTotalAmount(BigInteger::of(19990))
            ->setCurrencyCode('EUR');
        $entityManager->persist($receipt);
        $entityManager->flush();

        $manager = self::getContainer()->get(BillManager::class);
        $bill = $manager->createFromReceipt($receipt);

        self::assertSame(BillStatus::Pending, $bill->getStatus());
        self::assertSame($existingSupplier->getId(), $bill->getSupplier()->getId());
        self::assertSame($receipt, $bill->getElectronicInvoiceReceipt());
    }

    public function testTransitionActionRejectsAnInvalidTransition(): void
    {
        $this->expectException(InvalidTransitionException::class);

        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $supplier = new Client();
        $supplier->setCompany($this->company)->setName('Acme Supplies')->setIsClient(false)->setIsSupplier(true);
        $entityManager->persist($supplier);

        $bill = new Bill();
        $bill->setCompany($this->company)
            ->setSupplier($supplier)
            ->setStatus(BillStatus::Paid)
            ->setTotalAmount(BigInteger::of(1000))
            ->setCurrencyCode('EUR');
        $entityManager->persist($bill);
        $entityManager->flush();

        /** @var WorkflowInterface $stateMachine */
        $stateMachine = self::getContainer()->get('state_machine.bill');

        if (! $stateMachine->can($bill, Graph::TRANSITION_CONFIRM)) {
            throw new InvalidTransitionException(Graph::TRANSITION_CONFIRM);
        }
    }
}
