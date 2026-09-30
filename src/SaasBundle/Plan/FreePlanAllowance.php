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

namespace Augias\SaasBundle\Plan;

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use function array_filter;
use function array_values;

/**
 * One company on a free plan per account. Each company pays for its own
 * subscription, so without this an account could open as many free companies
 * as it liked (test instance, 30/09/2026). A company may go on a free plan
 * only if none of its owners already owns another company active on one.
 *
 * @see \Augias\SaasBundle\Tests\Plan\FreePlanAllowanceTest
 */
final readonly class FreePlanAllowance
{
    public function __construct(
        private SubscriptionProviderInterface $subscriptionProvider,
    ) {
    }

    public function allows(Company $company): bool
    {
        foreach ($company->getMemberships() as $membership) {
            if (CompanyRole::Owner === $membership->getRole() && $this->ownsFreeCompany($membership->getUser(), $company)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user owns a company active on a free plan, other than
     * $except. Asked before a company exists, to warn that it will need a
     * paid plan.
     */
    public function ownsFreeCompany(User $user, ?Company $except = null): bool
    {
        foreach ($user->getMemberships() as $membership) {
            if (CompanyRole::Owner !== $membership->getRole() || $membership->getCompany() === $except) {
                continue;
            }

            if ($this->isActiveOnFreePlan($membership->getCompany())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The plans this company may choose: all of them, less the free ones when
     * its owner already has a free company.
     *
     * @param array<Plan> $plans
     *
     * @return list<Plan>
     */
    public function plansFor(Company $company, array $plans): array
    {
        if ($this->allows($company)) {
            return array_values($plans);
        }

        return array_values(array_filter($plans, static fn (Plan $plan): bool => ! $plan->isFree()));
    }

    private function isActiveOnFreePlan(Company $company): bool
    {
        $subscription = $this->subscriptionProvider->getSubscriptionFor($company);

        return $subscription instanceof Subscription
            && SubscriptionStatus::ACTIVE === $subscription->getStatus()
            && $subscription->getPlan()->isFree();
    }
}
