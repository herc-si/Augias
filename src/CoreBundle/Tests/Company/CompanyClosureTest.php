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
use Augias\CoreBundle\Company\CompanyClosureNotifier;
use Augias\CoreBundle\Company\CompanyPurgeContext;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Mailer\Test\InteractsWithMailer;

/**
 * Closing a company waits for its date, and then takes everything with it —
 * issued invoices included, the one deletion the retention guard lets pass.
 */
#[CoversClass(CompanyClosure::class)]
final class CompanyClosureTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use InteractsWithMailer;

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
        self::assertSame([], $this->closure(new MockClock('2027-01-01'))->purgeDue());
    }

    /**
     * The owner hears of it three times: when it is asked for, a week
     * before — once — and when it is done.
     */
    public function testTheOwnerIsWrittenToAtEachStep(): void
    {
        $company = $this->shopWithAnIssuedInvoice();
        $owner = UserFactory::createOne(['email' => 'owner@closing.test', 'companies' => []]);
        $owner = $this->em()->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->addCompany($company, CompanyRole::Owner);
        $this->em()->flush();

        $this->closure(new MockClock('2026-09-26 10:00:00'))->schedule($company);
        $this->mailer()->sentEmails()->assertCount(1);
        $this->mailer()->sentEmails()->first()->assertTo('owner@closing.test')->assertSubject('Closing Shop is set to close on 26/10/2026');

        $remindAt = $this->closure(new MockClock('2026-10-20 08:00:00'));
        self::assertSame(1, $remindAt->remindDue());
        self::assertSame(0, $remindAt->remindDue(), 'Reminded once, not every day.');
        $this->mailer()->sentEmails()->assertCount(2);

        $this->closure(new MockClock('2026-10-27 00:00:00'))->purgeDue();

        $this->mailer()->sentEmails()->assertCount(3);
        $this->mailer()->sentEmails()->last()->assertTo('owner@closing.test')->assertSubject('Closing Shop has been closed');
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
        $container = self::getContainer();

        return new CompanyClosure(
            $this->em(),
            $container->get(CompanyRepository::class),
            $clock,
            $container->get(CompanyClosureNotifier::class),
            // The one the retention guard asks.
            $container->get(CompanyPurgeContext::class),
        );
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
