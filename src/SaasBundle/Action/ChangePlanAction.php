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
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Plan\FreePlanAllowance;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

final class ChangePlanAction extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly FreePlanAllowance $freePlanAllowance,
    ) {
    }

    public function __invoke(): Response
    {
        $company = $this->currentCompany();
        $subscription = $this->getSubscription($company);

        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'saas.flash.no_subscription');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $plans = $this->planRepository->findAllOrdered();
        $freePlanTaken = $company instanceof Company && ! $this->freePlanAllowance->allows($company);

        if ($freePlanTaken) {
            $plans = $this->freePlanAllowance->plansFor($company, $plans);
        }

        if ($plans === []) {
            $this->addFlash('error', 'saas.flash.no_plans_available');

            return $this->redirectToRoute('billing_index');
        }

        return $this->render('@AugiasSaas/subscription/change.html.twig', [
            'plans' => $plans,
            'subscription' => $subscription,
            'currentPlanId' => $subscription->getPlan()->getPlanId(),
            'freePlanTaken' => $freePlanTaken,
        ]);
    }

    private function getSubscription(?Company $company): ?Subscription
    {
        return $company instanceof Company ? $this->subscriptionProvider->getSubscriptionFor($company) : null;
    }

    private function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;
    }
}
