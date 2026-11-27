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

use const PHP_URL_HOST;
use Augias\ElectronicInvoicingBundle\Form\Type\CompanyIdentityType;
use Augias\ElectronicInvoicingBundle\Onboarding\CompanyIdentity;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Augias\ElectronicInvoicingBundle\Onboarding\PlatformConnectionGate;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use function assert;
use function is_string;
use function parse_url;
use function str_starts_with;

/**
 * The electronic invoicing setup: who the company is, the platform its
 * invoices go through, and that platform's verification of its identity.
 *
 * Reached from the dashboard's first steps, from the settings, after a first
 * subscription payment, and before the checkout when HERC SI does not know
 * the company's SIREN yet — `next` then names where to go once it is saved.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Functional\SetupTest
 */
final readonly class Index
{
    public function __construct(
        private ElectronicInvoicingOnboarding $onboarding,
        private FormFactoryInterface $formFactory,
        private RouterInterface $router,
        private PlatformConnectionGate $gate,
    ) {
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    #[Template('@AugiasElectronicInvoicing/Setup/index.html.twig')]
    public function __invoke(Request $request): array | RedirectResponse
    {
        $next = self::next($request);
        $identity = $this->onboarding->identity();
        $form = $this->formFactory->create(CompanyIdentityType::class, $identity, [
            'action' => $this->router->generate('_einvoicing_setup', $next === null ? [] : ['next' => $next]),
        ]);
        $form->handleRequest($request);
        $lookedUp = null;

        if ($form->isSubmitted()) {
            $lookUp = $form->get(CompanyIdentityType::LOOK_UP);
            assert($lookUp instanceof ClickableInterface);

            if ($lookUp->isClicked()) {
                $typed = $form->getData();
                assert($typed instanceof CompanyIdentity);
                $filled = $this->onboarding->lookUp($typed);
                // Found even when the name is not public (« diffusion partielle »).
                $lookedUp = $filled->siren !== null && $this->onboarding->registryHas($filled->siren);

                // A new form over the filled data: the submitted one would
                // show what was typed, and its errors for what was not yet.
                $form = $this->formFactory->create(CompanyIdentityType::class, $filled, [
                    'action' => $this->router->generate('_einvoicing_setup', $next === null ? [] : ['next' => $next]),
                ]);
            } elseif ($form->isValid()) {
                $saved = $form->getData();
                assert($saved instanceof CompanyIdentity);
                $this->onboarding->saveIdentity($saved);

                $session = $request->getSession();
                assert($session instanceof Session);
                $session->getFlashBag()->add('success', 'einvoicing.setup.identity.saved');

                return new RedirectResponse($next ?? $this->router->generate('_einvoicing_setup'));
            }
        }

        return [
            'form' => $form->createView(),
            'progress' => $this->onboarding->progress(),
            'lookedUp' => $lookedUp,
            'next' => $next,
            'waitsForCardUntil' => $this->gate->waitsForCardUntil(),
            'editing' => $request->query->getBoolean('edit') || $form->isSubmitted(),
        ];
    }

    /**
     * Where to go once the identity is saved, within the application only.
     */
    private static function next(Request $request): ?string
    {
        $next = $request->query->get('next');

        if (is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//') && null === parse_url($next, PHP_URL_HOST)) {
            return $next;
        }

        return null;
    }
}
