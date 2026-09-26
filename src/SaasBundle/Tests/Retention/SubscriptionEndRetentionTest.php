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

namespace Augias\SaasBundle\Tests\Retention;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\ClosureReason;
use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Company\CompanyClosureNotifier;
use Augias\CoreBundle\Company\CompanyPurgeContext;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\SaasBundle\Retention\CompanyRemovalTakesSubscriptionListener;
use Augias\SaasBundle\Retention\RenewalCancelsClosureListener;
use Augias\SaasBundle\Retention\SubscriptionEndRetention;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Ulid;
use Zenstruck\Mailer\Test\InteractsWithMailer;

/**
 * The terms promise the data stays ninety days after the subscription ends,
 * then goes: the end schedules the company's closure, renewing calls it off.
 */
#[CoversClass(SubscriptionEndRetention::class)]
#[CoversClass(RenewalCancelsClosureListener::class)]
#[CoversClass(CompanyRemovalTakesSubscriptionListener::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class SubscriptionEndRetentionTest extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use InteractsWithMailer;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheEndOfTheSubscriptionSchedulesTheDeletionNinetyDaysOn(): void
    {
        [$company] = $this->subscribed(SubscriptionStatus::CANCELLED, '2026-09-20 00:00:00', owner: 'owner@ended.test');

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();

        self::assertSame('2026-12-19', $company->getClosesAt()?->format('Y-m-d'));
        self::assertSame(ClosureReason::SubscriptionEnded, $company->getClosureReason());
        $this->mailer()->sentEmails()->assertCount(1);
        $this->mailer()->sentEmails()->first()
            ->assertTo('owner@ended.test')
            ->assertSubject('Subscription ended: Ended Shop will be deleted on 19/12/2026')
            ->assertContains('Renewing the subscription before that date calls the deletion off.');
    }

    /**
     * Ended before this was in place: the company still gets the notice an
     * owner asking for a closure gets, not a deletion the next morning.
     */
    public function testALongEndedSubscriptionStillGetsThirtyDaysNotice(): void
    {
        [$company] = $this->subscribed(SubscriptionStatus::EXPIRED, '2026-01-01 00:00:00');

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();

        self::assertSame('2026-10-26', $company->getClosesAt()?->format('Y-m-d'));
    }

    public function testAnEndedTrialCountsAsTheEnd(): void
    {
        [$company] = $this->subscribed(SubscriptionStatus::TRIAL, '2026-09-20 00:00:00');

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();

        self::assertSame(ClosureReason::SubscriptionEnded, $company->getClosureReason());
    }

    public function testARunningSubscriptionAndAnOwnersClosureAreLeftAlone(): void
    {
        [$running] = $this->subscribed(SubscriptionStatus::ACTIVE, '2026-09-20 00:00:00');
        [$cancelledLater] = $this->subscribed(SubscriptionStatus::CANCELLED, '2026-10-20 00:00:00');
        [$asked] = $this->subscribed(SubscriptionStatus::CANCELLED, '2026-09-20 00:00:00');
        $asked->scheduleClosure(new DateTimeImmutable('2026-10-01'));
        $this->em()->flush();

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();

        self::assertFalse($running->isClosing());
        self::assertFalse($cancelledLater->isClosing(), 'Paid until its end date.');
        self::assertSame(ClosureReason::Requested, $asked->getClosureReason());
        self::assertSame('2026-10-01', $asked->getClosesAt()?->format('Y-m-d'));
    }

    /**
     * Renewing gives the company back at once — it was read-only — and the
     * daily run, should it come first, does the same.
     */
    public function testRenewingCallsTheDeletionOff(): void
    {
        [$company, $subscription] = $this->subscribed(SubscriptionStatus::EXPIRED, '2026-01-01 00:00:00');
        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();
        self::assertTrue($company->isClosing());

        // What SubscriptionManager::renewSubscription() does on the provider's word.
        $subscription->setStatus(SubscriptionStatus::ACTIVE)->setEndDate(new DateTimeImmutable('+1 month'));
        $this->em()->flush();

        $this->em()->clear();
        $reloaded = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $reloaded);
        self::assertFalse($reloaded->isClosing());
        self::assertNull($reloaded->getClosureReason());
    }

    public function testTheDailyRunCallsOffADeletionWhoseSubscriptionCameBack(): void
    {
        [$company, $subscription] = $this->subscribed(SubscriptionStatus::ACTIVE, '2027-01-01 00:00:00');
        $company->scheduleClosure(new DateTimeImmutable('2026-12-01'), ClosureReason::SubscriptionEnded);
        $this->em()->flush();

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();

        self::assertFalse($company->isClosing());
    }

    /**
     * On the date the company goes, issued invoices and subscription with it.
     */
    public function testOnTheDateTheCompanyAndItsSubscriptionGo(): void
    {
        [$company, $subscription] = $this->subscribed(SubscriptionStatus::CANCELLED, '2026-09-20 00:00:00', owner: 'owner@gone.test');
        $client = ClientFactory::createOne(['company' => $company]);
        InvoiceFactory::createOne(['company' => $company, 'client' => $client, 'status' => InvoiceStatus::Paid]);
        $companyId = $company->getId();
        $subscriptionId = $subscription->getId();

        $this->retention(new MockClock('2026-09-26 10:00:00'))->reconcile();
        self::assertSame([], $this->closure(new MockClock('2026-12-18 23:00:00'))->purgeDue());

        $this->retention(new MockClock('2026-12-20 00:00:00'))->reconcile();
        self::assertSame(['Ended Shop'], $this->closure(new MockClock('2026-12-20 00:00:00'))->purgeDue());

        $this->em()->clear();
        self::assertNull($this->em()->find(Company::class, $companyId));
        $conn = $this->em()->getConnection();
        fwrite(STDERR, 'DIAG ' . json_encode(['c' => $conn->fetchAllAssociative('select hex(id) id, typeof(id) t from companies')], JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        self::assertNull($this->em()->find(Subscription::class, $subscriptionId), 'DIAG ' . json_encode([
            'sqlite' => $conn->fetchOne('select sqlite_version()'),
            'fk' => $conn->fetchOne('PRAGMA foreign_keys'),
            'sub' => $conn->fetchAllAssociative('select hex(id) id, typeof(id) t, typeof(subscriber_id) st, hex(subscriber_id) h from saas_subscription'),
            'company' => [(string) $companyId, $companyId->toRfc4122()],
            'ddl' => $conn->fetchOne("select sql from sqlite_master where name = 'saas_subscription'"),
            'listeners' => array_map(static fn ($l) => $l::class, iterator_to_array((function () { yield from []; })())),
        ], JSON_INVALID_UTF8_SUBSTITUTE));
        $this->mailer()->sentEmails()->last()->assertTo('owner@gone.test')->assertSubject("Ended Shop's data has been deleted");
    }

    /**
     * @return array{Company, Subscription}
     */
    private function subscribed(SubscriptionStatus $status, string $endDate, ?string $owner = null): array
    {
        $company = CompanyFactory::createOne(['name' => 'Ended Shop']);
        $company = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        if (null !== $owner) {
            $user = UserFactory::createOne(['email' => $owner, 'companies' => []]);
            $user = $this->em()->find(User::class, $user->getId());
            self::assertInstanceOf(User::class, $user);
            $user->addCompany($company, CompanyRole::Owner);
        }

        $plan = new Plan()->setName('Solo')->setPlanId('solo-' . new Ulid())->setPrice(900);
        $subscription = new Subscription()
            ->setSubscriber($company)
            ->setPlan($plan)
            ->setStatus($status)
            ->setStartDate(new DateTimeImmutable('2025-01-01'))
            ->setEndDate(new DateTimeImmutable($endDate));
        $this->em()->persist($plan);
        $this->em()->persist($subscription);
        $this->em()->flush();

        return [$company, $subscription];
    }

    private function retention(MockClock $clock): SubscriptionEndRetention
    {
        return new SubscriptionEndRetention($this->em(), $this->closure($clock), $clock);
    }

    private function closure(MockClock $clock): CompanyClosure
    {
        $container = self::getContainer();

        return new CompanyClosure(
            $this->em(),
            $container->get(CompanyRepository::class),
            $clock,
            $container->get(CompanyClosureNotifier::class),
            $container->get(CompanyPurgeContext::class),
        );
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
