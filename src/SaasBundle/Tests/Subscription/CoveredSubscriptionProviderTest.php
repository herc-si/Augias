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

namespace Augias\SaasBundle\Tests\Subscription;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\CompanyCoverage;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use SplObjectStorage;
use Symfony\Component\Uid\Ulid;

#[CoversClass(CoveredSubscriptionProvider::class)]
final class CoveredSubscriptionProviderTest extends TestCase
{
    use BuildsCoveredSubscriptionProvider;

    private Plan $agence;

    private Plan $solo;

    /**
     * @var SplObjectStorage<Company, Subscription>
     */
    private SplObjectStorage $subscriptions;

    /**
     * @var list<CompanyCoverage>
     */
    private array $coverages = [];

    private User $owner;

    protected function setUp(): void
    {
        $this->agence = new Plan()->setName('Agence')->setPlanId('price_agence')->setPrice(3999);
        $this->solo = new Plan()->setName('Solo')->setPlanId('price_solo')->setPrice(999);
        $this->subscriptions = new SplObjectStorage();
        $this->owner = new User();
    }

    public function testACoveredCompanyReadsItsHostsSubscription(): void
    {
        $host = $this->company($this->agence, SubscriptionStatus::ACTIVE);
        $covered = $this->company($this->solo, SubscriptionStatus::PENDING);
        $this->cover($covered, $host, '2026-10-01');

        $provider = $this->provider();

        self::assertSame($host, $provider->hostOf($covered));
        self::assertSame($this->subscriptions[$host], $provider->getSubscriptionFor($covered));
        self::assertSame($this->subscriptions[$host], $provider->getSubscriptionFor($host));
    }

    public function testACompanyWithoutACoverKeepsItsOwnSubscription(): void
    {
        $company = $this->company($this->solo, SubscriptionStatus::ACTIVE);

        $provider = $this->provider();

        self::assertNull($provider->hostOf($company));
        self::assertSame($this->subscriptions[$company], $provider->getSubscriptionFor($company));
    }

    /**
     * A host that moves to a plan for fewer companies: the latest covered fall
     * back to their own subscription, pending, and have to choose a plan.
     */
    public function testTheLatestCoveredFallBackWhenThePlanAllowsFewer(): void
    {
        $host = $this->company($this->agence, SubscriptionStatus::ACTIVE);
        $first = $this->company($this->solo, SubscriptionStatus::PENDING);
        $second = $this->company($this->solo, SubscriptionStatus::PENDING);
        $this->cover($second, $host, '2026-10-03');
        $this->cover($first, $host, '2026-10-01');

        $provider = $this->provider(['price_agence' => 2]);

        self::assertSame($host, $provider->hostOf($first));
        self::assertNull($provider->hostOf($second));
        self::assertSame($this->subscriptions[$second], $provider->getSubscriptionFor($second));
        self::assertSame([$first], $provider->coveredBy($host));
    }

    public function testAPlanForOneCompanyCoversNone(): void
    {
        $host = $this->company($this->solo, SubscriptionStatus::ACTIVE);
        $covered = $this->company($this->solo, SubscriptionStatus::PENDING);
        $this->cover($covered, $host, '2026-10-01');

        self::assertNull($this->provider()->hostOf($covered));
    }

    public function testAnUnlimitedPlanCoversThemAll(): void
    {
        $host = $this->company($this->agence, SubscriptionStatus::ACTIVE);
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->cover($this->company($this->solo, SubscriptionStatus::PENDING), $host, $day);
        }

        self::assertCount(3, $this->provider(['price_agence' => -1])->coveredBy($host));
    }

    public function testAnOwnersPaidUpAgencyHasRoomUntilItsFull(): void
    {
        $host = $this->company($this->agence, SubscriptionStatus::ACTIVE, owned: true);
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->cover($this->company($this->solo, SubscriptionStatus::PENDING, owned: true), $host, $day);
        }

        self::assertSame($host, $this->provider()->hostWithRoomFor($this->owner), 'Four companies of five.');

        $this->cover($this->company($this->solo, SubscriptionStatus::PENDING, owned: true), $host, '2026-10-04');
        self::assertNull($this->provider()->hostWithRoomFor($this->owner), 'Five of five.');
    }

    public function testAnAgencyThatIsNotPaidUpCoversNothingNew(): void
    {
        $this->company($this->agence, SubscriptionStatus::PAST_DUE, owned: true);

        self::assertNull($this->provider()->hostWithRoomFor($this->owner));
    }

    public function testSomeoneElsesAgencyIsNoHost(): void
    {
        $this->company($this->agence, SubscriptionStatus::ACTIVE, owned: false);

        self::assertNull($this->provider()->hostWithRoomFor($this->owner));
    }

    public function testCoveringPutsTheCompanyUnderTheHost(): void
    {
        $host = $this->company($this->agence, SubscriptionStatus::ACTIVE, owned: true);
        $company = $this->company($this->solo, SubscriptionStatus::PENDING, owned: true);
        $provider = $this->provider(persisted: $persisted);

        self::assertNull($provider->hostOf($company));
        $provider->cover($company, $host);

        self::assertCount(1, $persisted);
        self::assertSame($host, $provider->hostOf($company), 'Not the answer remembered before the cover.');
    }

    /**
     * @param array<string, int>         $allowances
     * @param list<CompanyCoverage>|null $persisted
     */
    private function provider(array $allowances = ['price_agence' => 5], ?array &$persisted = null): CoveredSubscriptionProvider
    {
        $inner = $this->createStub(SubscriptionProviderInterface::class);
        $inner->method('getSubscriptionFor')->willReturnCallback(
            fn (SubscribableInterface $company): ?Subscription => $company instanceof Company && $this->subscriptions->offsetExists($company) ? $this->subscriptions[$company] : null,
        );

        return $this->coveredSubscriptionProvider($inner, $this->coverages, $allowances, $this->owner, $persisted);
    }

    private function company(Plan $plan, SubscriptionStatus $status, bool $owned = false): Company
    {
        $company = new Company();
        new ReflectionProperty(Company::class, 'id')->setValue($company, new Ulid());
        $company->addUser($owned ? $this->owner : new User(), CompanyRole::Owner);

        $this->subscriptions[$company] = new Subscription()->setSubscriber($company)->setPlan($plan)->setStatus($status);

        return $company;
    }

    private function cover(Company $covered, Company $host, string $day): void
    {
        $coverage = new CompanyCoverage($covered, $host, new DateTimeImmutable($day));
        new ReflectionProperty(CompanyCoverage::class, 'id')->setValue($coverage, new Ulid());

        // Oldest first, as the repository returns them.
        $this->coverages[] = $coverage;
        usort($this->coverages, static fn (CompanyCoverage $a, CompanyCoverage $b): int => $a->getCreatedAt() <=> $b->getCreatedAt());
    }
}
