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

use Augias\ElectronicInvoicingBundle\Onboarding\OnboardingProgressProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class PaymentSuccess extends AbstractController
{
    public function __construct(
        private readonly OnboardingProgressProviderInterface $einvoicingOnboarding,
    ) {
    }

    /**
     * Straight on to electronic invoicing once subscribed, until it is set
     * up: the platform's identity check is paid for per company, so it waits
     * for a subscription rather than a trial.
     */
    public function __invoke(): Response
    {
        $this->addFlash('success', 'saas.flash.subscription_activated');

        return $this->redirectToRoute($this->einvoicingOnboarding->progress()->isDone() ? '_dashboard' : '_einvoicing_setup');
    }
}
