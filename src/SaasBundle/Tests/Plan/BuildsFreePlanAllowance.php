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

namespace Augias\SaasBundle\Tests\Plan;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\SaasBundle\Plan\FreePlanAllowance;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;

/**
 * A FreePlanAllowance whose membership queries answer from the entities in
 * memory, for tests that build their companies by hand.
 */
trait BuildsFreePlanAllowance
{
    private function freePlanAllowance(SubscriptionProviderInterface $subscriptions, bool $freePlanOnSale = true): FreePlanAllowance
    {
        $plans = $this->createStub(PlanRepositoryInterface::class);
        $plans->method('findAllOrdered')->willReturn($freePlanOnSale ? [new Plan()->setName('Free')->setPlanId('0')->setPrice(0)] : []);

        $owners = static fn (iterable $memberships): array => array_values(array_filter(
            [...$memberships],
            static fn (Membership $membership): bool => CompanyRole::Owner === $membership->getRole(),
        ));

        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('ownersOf')->willReturnCallback(static fn (Company $company): array => $owners($company->getMemberships()));
        $memberships->method('ownedBy')->willReturnCallback(static fn (User $user): array => $owners($user->getMemberships()));

        $filters = $this->createStub(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getFilters')->willReturn($filters);

        return new FreePlanAllowance(
            $subscriptions,
            $memberships,
            $entityManager,
            new CompanySelector($this->createStub(ManagerRegistry::class)),
            $plans,
        );
    }
}
