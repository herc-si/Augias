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

namespace Augias\CoreBundle\Listener;

use Augias\CoreBundle\Company\CompanyDomainResolver;
use Augias\CoreBundle\Company\HostType;
use Augias\CoreBundle\Company\ResolvedHost;
use Augias\CoreBundle\Entity\Company;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use function in_array;
use function str_starts_with;

/**
 * Resolves the inbound Host header against `AUGIAS_APPLICATION_URL` and the per-company
 * `custom_domain` column so downstream listeners can short-circuit company selection, and so
 * unknown hosts return 404 instead of leaking the multi-tenant selector.
 *
 * @see \Augias\CoreBundle\Tests\Listener\HostRoutingListenerTest
 */
final readonly class HostRoutingListener implements EventSubscriberInterface
{
    public const string REQUEST_ATTR = '_resolved_host';

    public const int PRIORITY = 30;

    private const array SELECTOR_ROUTES = [
        '_select_company',
        '_switch_company',
        '_create_company',
        '_onboarding',
    ];

    public function __construct(
        private CompanyDomainResolver $resolver,
        private RouterInterface $router,
        private FeatureGate $featureGate,
        private ?string $installed = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest() || $this->installed === null) {
            return;
        }

        $request = $event->getRequest();

        if ($this->isInstallerRequest($request)) {
            return;
        }

        $resolved = $this->resolver->resolve($request->getHost());

        if ($resolved->type === HostType::Unknown) {
            throw new NotFoundHttpException();
        }

        // A host this deployment keeps for itself is served, and nothing about
        // the request is made a tenant's: no company is switched into the
        // session, and the router context is left on the canonical host. What
        // such a host is *for* is decided by whatever routes answer on it,
        // which is not this listener's business.
        if ($resolved->isReserved()) {
            $request->attributes->set(self::REQUEST_ATTR, $resolved);

            return;
        }

        // Defensive: when a company has been downgraded to a plan without
        // `custom_domain`, the row still carries `Company::customDomain` but
        // the gate refuses to honour it. Fall back to canonical-host routing
        // by re-tagging the resolution as DefaultHost — the request continues
        // without forcing the downgraded tenant into the user's session, so
        // they can still reach the canonical app via the standard selector.
        if ($resolved->isCustomDomain()
            && $resolved->company instanceof Company
            && ! $this->featureGate->isEnabled('custom_domain', $resolved->company)
        ) {
            $resolved = new ResolvedHost(
                HostType::DefaultHost,
                $resolved->host,
                $resolved->scheme,
                $resolved->port,
            );
        }

        $request->attributes->set(self::REQUEST_ATTR, $resolved);

        if ($resolved->isCustomDomain()) {
            $this->syncRouterContext($resolved);

            if ($this->isSelectorRoute($request)) {
                throw new NotFoundHttpException();
            }
        }
    }

    private function isInstallerRequest(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');

        if (str_starts_with($route, '_install') || $route === '_system_install') {
            return true;
        }

        return str_starts_with($request->getPathInfo(), '/install');
    }

    private function isSelectorRoute(Request $request): bool
    {
        return in_array(
            (string) $request->attributes->get('_route', ''),
            self::SELECTOR_ROUTES,
            true,
        );
    }

    private function syncRouterContext(ResolvedHost $resolved): void
    {
        $context = $this->router->getContext();
        $context->setHost($resolved->host);
        $context->setScheme($resolved->scheme);

        if ($resolved->scheme === 'https') {
            $context->setHttpsPort($resolved->port);
        } else {
            $context->setHttpPort($resolved->port);
        }
    }
}
