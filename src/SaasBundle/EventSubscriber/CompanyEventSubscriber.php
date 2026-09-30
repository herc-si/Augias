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

namespace Augias\SaasBundle\EventSubscriber;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Event\CompanyCreatedEvent;
use Augias\SaasBundle\Plan\DefaultPlanProvider;
use Augias\SaasBundle\Plan\FreePlanAllowance;
use Augias\UserBundle\Entity\User;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\TrialAlreadyExistsException;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionManager;
use SolidWorx\Platform\SaasBundle\Trial\TrialManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function assert;

#[AsEventListener(CompanyCreatedEvent::class, 'onCompanyCreated')]
#[AsEventListener(KernelEvents::RESPONSE, 'onResponse')]
final class CompanyEventSubscriber
{
    private ?Subscription $subscription = null;

    private ?Company $company = null;

    public function __construct(
        private readonly DefaultPlanProvider $defaultPlanProvider,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly Security $security,
        private readonly TrialManagerInterface $trialManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FreePlanAllowance $freePlanAllowance,
    ) {
    }

    public function onCompanyCreated(CompanyCreatedEvent $event): void
    {
        $plan = $this->defaultPlanProvider->get();

        if ($plan instanceof Plan) {
            $this->subscription = $this->subscriptionManager->createSubscription(
                $event->company,
                $plan,
            );
            $this->company = $event->company;
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->subscription instanceof Subscription) {
            $user = $this->security->getUser();
            assert($user instanceof User);

            $plan = $this->subscription->getPlan();

            // A free plan needs no payment and no choice: it is active at once.
            // Sent to the plan page instead, a company on the free default was
            // put through a checkout for nothing (29/09/2026, test instance:
            // "could not create the payment session" right after sign-up).
            if ($plan->isFree()) {
                // One free company per account: a second one stays pending
                // and goes to the plan picker, where Free is not offered.
                if ($this->company instanceof Company && $this->freePlanAllowance->allows($this->company)) {
                    $this->subscriptionManager->activate($this->subscription);
                } else {
                    $event->setResponse($this->createPlanSelectionRedirect());
                }

                $this->subscription = null;

                return;
            }

            if (! $this->trialManager->userHasTrial($user) && $plan->getTrialDuration() instanceof DateInterval) {
                try {
                    // User is new and plan has a trial configured, start the trial atomically
                    $this->entityManager->wrapInTransaction(function () use ($user): void {
                        $this->subscriptionManager->startTrial($this->subscription);
                        $this->trialManager->createTrial($user, $this->subscription);
                    });
                } catch (TrialAlreadyExistsException) {
                    // Race condition: another request already created a trial for this user
                    $event->setResponse($this->createPlanSelectionRedirect());
                }
            } else {
                // User already had a trial — let them pick a plan for this new
                // company instead of silently checking them out on the default.
                $event->setResponse($this->createPlanSelectionRedirect());
            }

            $this->subscription = null;
        }
    }

    private function createPlanSelectionRedirect(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('saas_subscription_plans'));
    }
}
