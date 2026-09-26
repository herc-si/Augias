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

namespace Augias\BillBundle\Tests\Command;

use Augias\BillBundle\Command\MarkOverdueBillsCommand;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use SolidWorx\Platform\PlatformBundle\Console\IO;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Ulid;

/**
 * A supplier invoice left unpaid is late the day after its due date, in
 * every company — and only then.
 */
#[CoversClass(MarkOverdueBillsCommand::class)]
final class MarkOverdueBillsCommandTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testUnpaidBillsPastTheirDueDateAreMarkedOverdueInEveryCompany(): void
    {
        $other = CompanyFactory::createOne(['name' => 'Other']);
        self::assertInstanceOf(Company::class, $other);

        $late = $this->bill($this->company, new DateTimeImmutable('yesterday'), BillStatus::Pending);
        $lateElsewhere = $this->bill($other, new DateTimeImmutable('-10 days'), BillStatus::Pending);
        $dueToday = $this->bill($this->company, new DateTimeImmutable('today'), BillStatus::Pending);
        $draft = $this->bill($this->company, new DateTimeImmutable('yesterday'), BillStatus::Draft);
        $paid = $this->bill($this->company, new DateTimeImmutable('yesterday'), BillStatus::Paid);

        self::assertStringContainsString('Marked 2 supplier invoice(s) overdue.', $this->markOverdue());
        self::assertSame(BillStatus::Overdue, $this->statusOf($late, $this->company));
        self::assertSame(BillStatus::Overdue, $this->statusOf($lateElsewhere, $other));
        self::assertSame(BillStatus::Pending, $this->statusOf($dueToday, $this->company), 'Not late on the day it falls due.');
        self::assertSame(BillStatus::Draft, $this->statusOf($draft, $this->company));
        self::assertSame(BillStatus::Paid, $this->statusOf($paid, $this->company));

        self::assertStringContainsString('Marked 0 supplier invoice(s) overdue.', $this->markOverdue());
    }

    private function markOverdue(): string
    {
        $command = new Application(self::$kernel)->find('augias:bills:mark-overdue');
        $command = $command instanceof LazyCommand ? $command->getCommand() : $command;
        self::assertInstanceOf(MarkOverdueBillsCommand::class, $command);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command->setIo(new IO($input, $output));

        self::assertSame(0, $command->run($input, $output));

        return $output->fetch();
    }

    private function bill(Company $company, DateTimeImmutable $dueDate, BillStatus $status): Ulid
    {
        $em = $this->em();
        $company = $em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $supplier = new Client();
        $supplier->setCompany($company)->setName('Supplier ' . new Ulid())->setIsClient(false)->setIsSupplier(true);
        $em->persist($supplier);

        $bill = new Bill();
        $bill->setCompany($company)
            ->setSupplier($supplier)
            ->setBillNumber('SUP-' . new Ulid())
            ->setIssueDate($dueDate->modify('-1 month'))
            ->setDueDate($dueDate)
            ->setTotalAmount(BigInteger::of(10000))
            ->setCurrencyCode('EUR')
            ->setStatus($status);
        $em->persist($bill);
        $em->flush();

        return $bill->getId();
    }

    private function statusOf(Ulid $billId, Company $company): ?BillStatus
    {
        self::getContainer()->get(CompanySelector::class)->switchCompany($company->getId());
        $this->em()->clear();

        return $this->em()->find(Bill::class, $billId)?->getStatus();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
