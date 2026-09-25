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

namespace Augias\BillBundle\Tests\Listener;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Listener\CreateBillFromReceiptListener;
use Augias\BillBundle\Manager\BillManager;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Event\ElectronicInvoiceReceiptImportedEvent;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(CreateBillFromReceiptListener::class)]
final class CreateBillFromReceiptListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testSkipsAReceiptThatAlreadyHasABill(): void
    {
        $receipt = new ElectronicInvoiceReceipt();

        $billRepository = M::mock(BillRepository::class);
        $billRepository->shouldReceive('findOneBy')
            ->with(['electronicInvoiceReceipt' => $receipt])
            ->andReturn(new Bill());

        // BillManager is final, so it cannot be mocked — a real one whose
        // EntityManager expects nothing fails just as loudly if the listener
        // wrongly tries to create a bill.
        $entityManager = M::mock(EntityManagerInterface::class);
        $entityManager->shouldNotReceive('persist');
        $billManager = new BillManager($entityManager, M::mock(ClientRepository::class), M::mock(SystemConfig::class, ['isVatExempt' => false]));

        new CreateBillFromReceiptListener($billRepository, $billManager, M::mock(LoggerInterface::class))(
            new ElectronicInvoiceReceiptImportedEvent($receipt)
        );
    }

    /**
     * The poll imports a whole batch, so a receipt that cannot be turned into a
     * bill has to be logged and stepped over rather than thrown — otherwise one
     * malformed invoice would abort every later one in the same run.
     */
    public function testSwallowsAndLogsAFailureSoTheBatchContinues(): void
    {
        $receipt = new ElectronicInvoiceReceipt();

        $billRepository = M::mock(BillRepository::class);
        $billRepository->shouldReceive('findOneBy')->andReturnNull();

        $entityManager = M::mock(EntityManagerInterface::class);
        $entityManager->shouldReceive('persist')
            ->andThrow(new RuntimeException('provider sent garbage'));

        $clientRepository = M::mock(ClientRepository::class);
        $clientRepository->shouldReceive('findOneByName')->andReturnNull();

        $billManager = new BillManager($entityManager, $clientRepository, M::mock(SystemConfig::class, ['isVatExempt' => false]));

        $logger = M::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();

        new CreateBillFromReceiptListener($billRepository, $billManager, $logger)(
            new ElectronicInvoiceReceiptImportedEvent($receipt)
        );
    }
}
