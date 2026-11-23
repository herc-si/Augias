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

namespace Augias\ElectronicInvoicingBundle\Action\SuperPdp;

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Onboarding\PlatformConnectionGate;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdpProvider;
use Augias\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Sends the user to SUPER PDP to connect the company's account to this
 * deployment's application. They come back to {@see Callback}.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Action\SuperPdp\ConnectTest
 */
final readonly class Connect
{
    public function __construct(
        private SuperPdpConnector $connector,
        private PlatformConnectionGate $gate,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, ElectronicInvoiceProviderSetting $setting, #[CurrentUser] User $user): RedirectResponse
    {
        if (SuperPdpProvider::getName() !== $setting->getProvider() || ! $this->connector->isAvailable()) {
            throw new NotFoundHttpException();
        }

        // Every way in comes through here: the setup, and the platform's
        // settings page.
        if ($this->gate->waitsForCardUntil() !== null) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', 'einvoicing.setup.platform.trial_needs_card_flash');
            }

            return new RedirectResponse($this->urlGenerator->generate('_einvoicing_setup'));
        }

        return new RedirectResponse($this->connector->start($setting, $request->getSession(), $user->getEmail()));
    }
}
