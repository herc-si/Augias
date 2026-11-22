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
use Psr\Log\LoggerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\PaymentIntegrationException;
use SolidWorx\Platform\SaasBundle\Integration\PaymentIntegrationInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Uid\Ulid;

/**
 * Stripe's customer portal, where the subscriber changes the card and pays
 * a refused renewal. The "manage payment method" button used to open a new
 * checkout instead — a second subscription, not another card.
 *
 * @see \Augias\SaasBundle\Tests\Action\BillingPortalActionTest
 */
final class BillingPortalAction extends AbstractController
{
    public function __construct(
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly PaymentIntegrationInterface $payments,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;
        $subscription = $company !== null ? $this->subscriptionProvider->getSubscriptionFor($company) : null;

        // Nothing billed by Stripe yet: there is no card to change, only a
        // plan to choose.
        if (! $subscription instanceof Subscription || ! $subscription->isExternallyBilled()) {
            return $this->redirectToRoute('billing_index');
        }

        try {
            return $this->redirect($this->payments->getCustomerPortalUrl($subscription));
        } catch (PaymentIntegrationException $e) {
            $this->logger->error('The customer portal could not open: ' . $e->getMessage(), ['exception' => $e]);
            $this->addFlash('error', 'saas.flash.portal_failed');

            return $this->redirectToRoute('billing_index');
        }
    }
}
