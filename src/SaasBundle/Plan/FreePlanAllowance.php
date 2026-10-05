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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\Uid\Ulid;
use function array_filter;
use function array_values;

/**
 * One company on a free plan per account. Each company pays for its own
 * subscription, so without this an account could open as many free companies
 * as it liked (test instance, 30/09/2026). A company may go on a free plan
 * only if none of its owners already owns another company active on one.
 *
 * Memberships are read by query, with the company filter off. They carry a
 * company, so a collection loaded under the filter holds the selected company
 * only: the answer would depend on when Doctrine happened to load it — for
 * the signed-in user, before the filter; for another owner, after.
 *
 * With no free plan on sale, there is nothing to hold back: companies already
 * on Free keep it, and nobody is told Free is "taken" when it is simply gone.
 *
 * @see \Augias\SaasBundle\Tests\Plan\FreePlanAllowanceTest
 */
final readonly class FreePlanAllowance
{
    public function __construct(
        private SubscriptionProviderInterface $subscriptionProvider,
        private MembershipRepository $memberships,
        private EntityManagerInterface $entityManager,
        private CompanySelector $companySelector,
        private PlanRepositoryInterface $plans,
    ) {
    }

    public function allows(Company $company): bool
    {
        if (! $this->freePlanOnSale()) {
            return true;
        }

        foreach ($this->acrossCompanies(fn (): array => $this->memberships->ownersOf($company)) as $membership) {
            if ($this->ownsFreeCompany($membership->getUser(), $company)) {
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
        foreach ($this->acrossCompanies(fn (): array => $this->memberships->ownedBy($user)) as $membership) {
            $owned = $membership->getCompany();

            if ($except instanceof Company && $owned->getId()->equals($except->getId())) {
                continue;
            }

            if ($this->isActiveOnFreePlan($owned)) {
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

    private function freePlanOnSale(): bool
    {
        foreach ($this->plans->findAllOrdered() as $plan) {
            if ($plan->isFree()) {
                return true;
            }
        }

        return false;
    }

    private function isActiveOnFreePlan(Company $company): bool
    {
        $subscription = $this->subscriptionProvider->getSubscriptionFor($company);

        return $subscription instanceof Subscription
            && SubscriptionStatus::ACTIVE === $subscription->getStatus()
            && $subscription->getPlan()->isFree();
    }

    /**
     * @param callable(): list<Membership> $read
     *
     * @return list<Membership>
     */
    private function acrossCompanies(callable $read): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if (! $wasEnabled) {
            return $read();
        }

        $selected = $this->companySelector->getCompany();
        $filters->disable('company');

        try {
            return $read();
        } finally {
            // Through the selector, not $filters->enable(): Doctrine re-enables
            // a filter without its parameters, and the company filter without
            // its company filters nothing for the rest of the request.
            if ($selected instanceof Ulid) {
                $this->companySelector->switchCompany($selected);
            } else {
                $filters->enable('company');
            }
        }
    }
}
