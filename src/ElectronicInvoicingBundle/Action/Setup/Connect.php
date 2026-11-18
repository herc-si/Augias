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

use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Step 2 of the setup: the SUPER PDP setting, created if need be, then
 * SUPER PDP's own consent page. Its callback comes back to the setup.
 */
final class Connect extends AbstractController
{
    public const string CSRF = 'einvoicing_setup_connect';

    /** Read by the callback: the connection was started from the setup. */
    public const string SESSION_KEY = 'einvoicing_setup_return';

    public function __construct(
        private readonly ElectronicInvoicingOnboarding $onboarding,
        private readonly SuperPdpConnector $connector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $this->connector->isAvailable()) {
            throw new NotFoundHttpException();
        }

        if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'einvoicing.setup.invalid_token');

            return $this->redirectToRoute('_einvoicing_setup');
        }

        $setting = $this->onboarding->superPdpSetting();
        $this->entityManager->flush();

        $request->getSession()->set(self::SESSION_KEY, true);

        return $this->redirectToRoute('_einvoicing_super_pdp_connect', ['id' => (string) $setting->getId()]);
    }
}
