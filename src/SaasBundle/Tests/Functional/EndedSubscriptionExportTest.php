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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\CoreBundle\Company\ClosureReason;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\Test\SaasKernel;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Once the subscription is over, the application is closed but for the
 * export — which the page says, with the date the data goes.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class EndedSubscriptionExportTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheEndedSubscriptionPageLeadsToTheExport(): void
    {
        $owner = $this->ownerOfAnEndedCompany(SubscriptionStatus::CANCELLED);

        $this->browser()
            ->actingAs($owner)
            ->visit('/dashboard')
            ->assertSee('Your data will be deleted on 19/12/2026.')
            ->assertSeeElement('a[href="/profile/exports"]')
            ->visit('/profile/exports')
            ->assertSuccessful()
            ->assertSee('The subscription has ended: this company will be deleted on 19/12/2026.')
            ->assertSee('Renew the subscription')
            ->assertNotSee('Cancel the closure')
            ->visit('/invoices/')
            ->assertSee('Your subscription has been cancelled.');
    }

    public function testAnEndedTrialSaysTheSame(): void
    {
        $owner = $this->ownerOfAnEndedCompany(SubscriptionStatus::TRIAL);

        $this->browser()
            ->actingAs($owner)
            ->visit('/dashboard')
            ->assertSee('Your data will be deleted on 19/12/2026.');
    }

    private function ownerOfAnEndedCompany(SubscriptionStatus $status): User
    {
        $company = CompanyFactory::createOne(['name' => 'Ended Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);
        $company->scheduleClosure(new DateTimeImmutable('2026-12-19'), ClosureReason::SubscriptionEnded);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->addCompany($company, CompanyRole::Owner);

        $plan = new Plan()->setName('Solo')->setPlanId('solo-' . new Ulid())->setPrice(900);
        $this->em->persist($plan);
        $this->em->persist(new Subscription()
            ->setSubscriber($company)
            ->setPlan($plan)
            ->setStatus($status)
            ->setStartDate(new DateTimeImmutable('2025-01-01'))
            ->setEndDate(new DateTimeImmutable('2025-09-20')));
        $this->em->flush();

        return $owner;
    }
}
