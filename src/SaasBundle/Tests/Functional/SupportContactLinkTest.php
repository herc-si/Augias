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
 * "Contact support" leads where the operator says (AUGIAS_SAAS_SUPPORT_URL),
 * and is left out when nothing is said: the code names no operator.
 */
#[Group('functional')]
#[Group('saas-kernel')]
final class SupportContactLinkTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_SAAS_SUPPORT_URL'], $_ENV['AUGIAS_SAAS_SUPPORT_URL']);

        parent::tearDown();
    }

    public function testTheLinkLeadsWhereTheOperatorSays(): void
    {
        $_SERVER['AUGIAS_SAAS_SUPPORT_URL'] = $_ENV['AUGIAS_SAAS_SUPPORT_URL'] = 'https://support.example.com/contact';

        $this->browser()
            ->actingAs($this->ownerOfACancelledCompany())
            ->visit('/invoices/')
            ->assertSee('Your subscription has been cancelled.')
            ->assertSeeElement('a[href="https://support.example.com/contact"]');
    }

    public function testNoLinkWithoutAnAddress(): void
    {
        $this->browser()
            ->actingAs($this->ownerOfACancelledCompany())
            ->visit('/invoices/')
            ->assertSee('Your subscription has been cancelled.')
            ->assertNotSee('Contact Support');
    }

    private function ownerOfACancelledCompany(): User
    {
        $company = CompanyFactory::createOne(['name' => 'Cancelled Shop']);
        $company = $this->em->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $company);

        $owner = UserFactory::createOne(['companies' => []]);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $owner->addCompany($company, CompanyRole::Owner);

        $plan = new Plan()->setName('Solo')->setPlanId('solo-' . new Ulid())->setPrice(900);
        $this->em->persist($plan);
        $this->em->persist(new Subscription()
            ->setSubscriber($company)
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::CANCELLED)
            ->setStartDate(new DateTimeImmutable('2025-01-01'))
            ->setEndDate(new DateTimeImmutable('2025-09-20')));
        $this->em->flush();

        return $owner;
    }
}
