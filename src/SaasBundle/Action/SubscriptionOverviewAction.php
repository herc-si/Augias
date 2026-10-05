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

namespace Augias\SaasBundle\Action;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Carbon\CarbonImmutable;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

final class SubscriptionOverviewAction extends AbstractController
{
    public function __construct(
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CoveredSubscriptionProvider $coverage,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(): Response
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return $this->redirectToRoute('saas_subscription_plans');
        }

        $company = $this->companyRepository->find($companyId);

        if ($company === null) {
            return $this->redirectToRoute('saas_subscription_plans');
        }

        $subscription = $this->subscriptionProvider->getSubscriptionFor($company);

        if (! $subscription instanceof Subscription) {
            return $this->redirectToRoute('saas_subscription_plans');
        }

        return $this->render('@AugiasSaas/subscription/overview.html.twig', [
            'subscription' => $subscription,
            'plan' => $subscription->getPlan(),
            'isTrial' => $subscription->getStatus() === SubscriptionStatus::TRIAL,
            'isFree' => $subscription->getPlan()->isFree(),
            'isPastDue' => $subscription->getStatus() === SubscriptionStatus::PAST_DUE,
            'isPaused' => $subscription->getStatus() === SubscriptionStatus::PAUSED,
            'hasExternalBilling' => $subscription->isExternallyBilled(),
            'trialDaysRemaining' => $this->trialDaysRemaining($subscription),
            // Paid for by another company's subscription, managed from there.
            'coveredBy' => $this->coverage->hostOf($company),
            // Or the companies this one's subscription pays for, its own aside.
            'coveredCompanies' => $this->coverage->coveredBy($company),
            'companyAllowance' => $this->coverage->allowance($subscription->getPlan()),
        ]);
    }

    private function trialDaysRemaining(Subscription $subscription): ?int
    {
        if ($subscription->getStatus() !== SubscriptionStatus::TRIAL) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $end = CarbonImmutable::instance($subscription->getEndDate());

        if ($end <= $now) {
            return 0;
        }

        return max(0, (int) $now->diffInDays($end, false));
    }
}
