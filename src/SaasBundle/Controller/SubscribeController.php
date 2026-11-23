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

namespace Augias\SaasBundle\Controller;

use Augias\CoreBundle\Company\CompanySelectorInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Telemetry\TelemetryEvent;
use Augias\ElectronicInvoicingBundle\Onboarding\OnboardingProgressProviderInterface;
use Augias\SaasBundle\Action\ChoosePlanAction;
use Augias\SaasBundle\Payment\CheckoutGuard;
use Augias\UserBundle\Entity\User;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\Options;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use function strtolower;

/**
 * @see \Augias\SaasBundle\Tests\Controller\SubscribeControllerTest
 */
class SubscribeController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptionManager,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelectorInterface $companySelector,
        private readonly PlanRepositoryInterface $planRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Telemetry $telemetry,
        private readonly ClockInterface $clock,
        private readonly OnboardingProgressProviderInterface $einvoicingOnboarding,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $subscription = $this->getSubscription();
        if (! $subscription instanceof Subscription) {
            $this->addFlash('error', 'saas.flash.no_subscription_short');
            return $this->redirectToRoute('_dashboard');
        }

        $refusal = CheckoutGuard::refusal($subscription, $this->clock->now());

        if ($refusal !== null) {
            $this->addFlash('warning', $refusal);

            return $this->redirectToRoute('billing_index');
        }

        // HERC SI invoices the subscriber: without a SIREN it would be a
        // private individual, its invoice reported instead of sent
        // electronically. Asked once, by the setup's first step, which comes
        // back here — the chosen plan with it.
        if (! $this->einvoicingOnboarding->progress()->hasSiren) {
            return $this->redirectToRoute('_einvoicing_setup', ['next' => $request->getRequestUri()]);
        }

        // The chosen plan id (LS variant) is passed in via query parameter
        // from ChoosePlanAction / ConfirmPlanChangeAction. We swap it onto the
        // subscription IN MEMORY only — the checkout URL builder reads
        // `subscription->getPlan()->getPlanId()` for the variant — and then
        // refresh the entity afterwards to discard the in-memory mutation.
        // Nothing is flushed, so a failed checkout leaves the local plan
        // untouched. The webhook handler commits the swap on confirmation.
        $pendingPlanId = (string) $request->query->get(ChoosePlanAction::PENDING_PLAN_QUERY_PARAMETER, '');
        $swapped = false;

        if ($pendingPlanId !== '' && $pendingPlanId !== $subscription->getPlan()->getPlanId()) {
            $targetPlan = $this->planRepository->find($pendingPlanId);

            if ($targetPlan instanceof Plan && $targetPlan->isActive()) {
                $subscription->setPlan($targetPlan);
                $swapped = true;
            }
        }

        // What is left of the trial carries over to the paid subscription
        // only for the plan that has a trial — the base one. A company in its
        // trial that picks a higher plan pays now (decided by Rémi,
        // 19/11/2026): the trial is a way to try Augias, not a month of a
        // higher plan for free. Read after the swap above, so the plan is the
        // one being paid for. Every other path (expired trial, cancelled,
        // already billed elsewhere) is charged at once as well.
        $grantsTrialExtension = $subscription->getStatus() === SubscriptionStatus::TRIAL
            && ! $subscription->isExternallyBilled()
            && $subscription->getEndDate() > $this->clock->now()
            && $subscription->getPlan()->getTrialDuration() instanceof DateInterval;

        $options = Options::new()
            ->withEmail($user->getEmail())
            ->withSkipTrial(! $grantsTrialExtension);

        // Capture the plan the user is checking out (the in-memory swapped
        // target when a pending plan id was supplied) before the `finally`
        // block refreshes the entity and discards the swap.
        $planName = strtolower($subscription->getPlan()->getName());

        try {
            $checkoutUrl = $this->subscriptionManager
                ->getCheckoutUrl($subscription, $options);
        } catch (PaymentIntegrationException | HttpExceptionInterface | TransportExceptionInterface $e) {
            // PaymentIntegrationException is what the Stripe integration
            // throws, an unset key included: without it here, a checkout that
            // cannot start was a 500 page instead of this message (test
            // instance, 29/09/2026). The HTTP client exceptions are Lemon
            // Squeezy's.
            $this->logger->error('The checkout could not start: ' . $e->getMessage(), ['exception' => $e]);
            $this->telemetry->event(TelemetryEvent::SaasCheckoutFailed, ['plan' => $planName]);
            $this->addFlash('error', 'saas.flash.checkout_failed');

            return $this->redirectToRoute('billing_index');
        } finally {
            // Discard the in-memory plan swap. The subscription entity is
            // reloaded from the database so the in-memory mutation is never
            // persisted. The webhook handler is the only authority for
            // committing a paid plan change locally.
            if ($swapped) {
                $this->entityManager->refresh($subscription);
            }
        }

        $this->telemetry->event(TelemetryEvent::SaasCheckoutStarted, ['plan' => $planName]);

        return $this->redirect($checkoutUrl);
    }

    private function getSubscription(): ?Subscription
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        $company = $this->companyRepository->find($companyId);

        if (! $company instanceof Company) {
            return null;
        }

        return $this->subscriptionManager->getSubscriptionFor($company);
    }
}
