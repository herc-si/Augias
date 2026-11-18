<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Action\Setup;

use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Step 3 of the setup: asks the platform now whether it has verified the
 * company, instead of waiting for the hourly check.
 */
final class Check extends AbstractController
{
    public const string CSRF = 'einvoicing_setup_check';

    public function __construct(
        private readonly ElectronicInvoicingOnboarding $onboarding,
        private readonly ElectronicInvoiceAccountMonitor $monitor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'einvoicing.setup.invalid_token');

            return $this->redirectToRoute('_einvoicing_setup');
        }

        $setting = $this->onboarding->platform();

        if ($setting !== null) {
            $status = $this->monitor->check($setting);
            $this->entityManager->flush();

            if ($status !== null && ! $status->answered) {
                $this->addFlash('warning', 'einvoicing.setup.verification.unreachable');
            }
        }

        return $this->redirectToRoute('_einvoicing_setup');
    }
}
