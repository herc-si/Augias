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
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Telemetry\TelemetryEvent;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * @see \Augias\SaasBundle\Tests\Action\SelectPlanActionTest
 */
final class SelectPlanAction extends AbstractController
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly Telemetry $telemetry,
    ) {
    }

    public function __invoke(): Response
    {
        $subscription = $this->getSubscription();

        if ($subscription instanceof Subscription && $subscription->getStatus() === SubscriptionStatus::ACTIVE) {
            return $this->redirectToRoute('billing_index');
        }

        $plans = $this->planRepository->findAllOrdered();

        if ($plans === []) {
            $this->addFlash('error', 'saas.flash.no_plans_available');

            return $this->redirectToRoute('_dashboard');
        }

        // Straight to checkout when there is nothing to choose — but only for a
        // plan that is paid for. A lone free plan is shown, not checked out.
        if (count($plans) === 1 && ! $plans[0]->isFree()) {
            return $this->redirectToRoute('saas_subscription_checkout');
        }

        $this->telemetry->event(TelemetryEvent::SaasPricingPageViewed);

        return $this->render('@AugiasSaas/subscription/pricing.html.twig', [
            'plans' => $plans,
            'subscription' => $subscription,
        ]);
    }

    private function getSubscription(): ?Subscription
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        $company = $this->companyRepository->find($companyId);

        if ($company === null) {
            return null;
        }

        return $this->subscriptionProvider->getSubscriptionFor($company);
    }
}
