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

namespace Augias\CoreBundle\Tests\Company;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Closing a company waits for its date, and then takes everything with it —
 * issued invoices included, the one deletion the retention guard lets pass.
 */
#[CoversClass(CompanyClosure::class)]
final class CompanyClosureTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testClosingIsScheduledThirtyDaysAhead(): void
    {
        $company = $this->shopWithAnIssuedInvoice();

        $closesAt = $this->closure(new MockClock('2026-09-26 10:00:00'))->schedule($company);

        self::assertSame('2026-10-26', $closesAt->format('Y-m-d'));
        self::assertTrue($company->isClosing());
    }

    public function testNothingGoesBeforeTheDate(): void
    {
        $company = $this->shopWithAnIssuedInvoice();
        $company->scheduleClosure(new DateTimeImmutable('2026-10-26 10:00:00'));
        $this->em()->flush();

        self::assertSame([], $this->closure(new MockClock('2026-10-25 23:00:00'))->purgeDue());

        $this->em()->clear();
        self::assertInstanceOf(Company::class, $this->em()->find(Company::class, $company->getId()));
    }

    public function testOnceThePeriodIsOverTheCompanyGoesWithItsInvoices(): void
    {
        $company = $this->shopWithAnIssuedInvoice();
        $companyId = $company->getId();
        $company->scheduleClosure(new DateTimeImmutable('2026-10-26 10:00:00'));
        $this->em()->flush();

        self::assertSame(['Closing Shop'], $this->closure(new MockClock('2026-10-27 00:00:00'))->purgeDue());

        $this->em()->clear();
        self::assertNull($this->em()->find(Company::class, $companyId));
        self::assertSame([], $this->em()->getRepository(Invoice::class)->findBy(['invoiceId' => 'CLOSE-1']));
    }

    /**
     * Outside a closure the guard still holds: deleting the company directly
     * is refused while it has issued invoices.
     */
    public function testWithoutAClosureTheCompanyIsNotDeleted(): void
    {
        $company = $this->shopWithAnIssuedInvoice();

        $this->expectException(DocumentMustBeKept::class);

        self::getContainer()->get(CompanyRepository::class)->deleteCompany($company->getId());
    }

    public function testCallingItOffKeepsEverything(): void
    {
        $company = $this->shopWithAnIssuedInvoice();
        $closure = $this->closure(new MockClock('2026-09-26 10:00:00'));
        $closure->schedule($company);

        $closure->cancel($company);

        self::assertFalse($company->isClosing());
        self::assertSame([], new CompanyClosure($this->em(), self::getContainer()->get(CompanyRepository::class), new MockClock('2027-01-01'))->purgeDue());
    }

    private function shopWithAnIssuedInvoice(): Company
    {
        $company = CompanyFactory::createOne(['name' => 'Closing Shop']);
        self::assertInstanceOf(Company::class, $company);
        $client = ClientFactory::createOne(['company' => $company]);
        InvoiceFactory::createOne(['company' => $company, 'client' => $client, 'status' => InvoiceStatus::Paid, 'invoiceId' => 'CLOSE-1']);

        $managed = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $managed);

        return $managed;
    }

    private function closure(MockClock $clock): CompanyClosure
    {
        $closure = new CompanyClosure($this->em(), self::getContainer()->get(CompanyRepository::class), $clock);
        // The retention guard asks the container's instance whether a company is
        // being purged; make this one be it.
        self::getContainer()->set(CompanyClosure::class, $closure);

        return $closure;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
