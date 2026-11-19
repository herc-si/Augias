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

use Augias\ElectronicInvoicingBundle\Action\Setup\Connect as SetupConnect;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceAccountMonitor;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\ElectronicAddressResolver;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpAccessTokens;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApiException;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnectionFailed;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpConnector;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use function assert;

/**
 * Where SUPER PDP sends the user back, with a code to trade for the company's
 * tokens — or with `error=access_denied` when they declined.
 *
 * The address of this route is the redirect URL registered on SUPER PDP's
 * side for the application, and must not change.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Action\SuperPdp\CallbackTest
 */
final readonly class Callback
{
    public function __construct(
        private SuperPdpConnector $connector,
        private ElectronicInvoiceAccountMonitor $accountMonitor,
        private SuperPdpAccessTokens $accessTokens,
        private ElectronicAddressResolver $addressResolver,
        private ElectronicInvoiceProviderSettingRepository $settings,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        assert($session instanceof Session);
        $flashes = $session->getFlashBag();
        // Back to the setup when the connection was started from there.
        $fromSetup = true === $session->remove(SetupConnect::SESSION_KEY);
        $redirect = new RedirectResponse($this->router->generate($fromSetup ? '_einvoicing_setup' : '_einvoicing_providers'));

        $code = $request->query->getString('code');

        if ('' === $code) {
            $flashes->add('warning', 'access_denied' === $request->query->getString('error') ? 'einvoicing.super_pdp.connect.denied' : 'einvoicing.super_pdp.connect.failed');

            return $redirect;
        }

        try {
            $setting = $this->connector->complete($session, $request->query->getString('state'), $code);
        } catch (SuperPdpConnectionFailed $e) {
            $flashes->add('danger', $e->getMessage());

            return $redirect;
        }

        // The first platform a company sets up is the one invoices go to.
        if ([] === $this->settings->findSwitchedOn()) {
            $setting->setActive(true);
        }

        // Written before anything uses them: the tokens are read back in SQL.
        $this->entityManager->flush();

        // Asked at once: the company has just been through SUPER PDP's
        // verification, and may still be waiting on it.
        $status = $this->accountMonitor->check($setting);

        $this->entityManager->flush();

        // The address the platform registered for the company, kept now: the
        // directory answers only a verified account, and a lookup that fails
        // leaves things as they were.
        if (null !== $status && $status->verification->isUsable()) {
            try {
                $this->addressResolver->resolveOwnAddress($this->accessTokens->accessToken($setting->getSettings()), $setting->getCompany());
            } catch (SuperPdpApiException) {
            }
        }

        $flashes->add('success', 'einvoicing.super_pdp.connect.connected');

        if (null !== $status && ! $status->verification->isUsable()) {
            $flashes->add('warning', $status->answered ? 'einvoicing.account.held' : 'einvoicing.account.unchecked');
        }

        return $redirect;
    }
}
