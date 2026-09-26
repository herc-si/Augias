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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Company\ResolvedHost;
use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use function assert;
use function count;
use function in_array;

/**
 * @see \Augias\CoreBundle\Tests\Listener\CompanyEventSubscriberTest
 */
final readonly class CompanyEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private CompanySelector $companySelector,
        private Security $security,
        private ?string $installed = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (null === $this->installed || ! $event->isMainRequest() || $request->attributes->get('_stateless')) {
            return;
        }

        if ($this->companySelector->getCompany() instanceof Ulid) {
            return;
        }

        $session = $request->getSession();
        assert($session instanceof SessionInterface);

        $resolved = $request->attributes->get(HostRoutingListener::REQUEST_ATTR);

        if ($resolved instanceof ResolvedHost && $resolved->isCustomDomain() && $resolved->company instanceof Company) {
            $companyId = $resolved->company->getId();
            $user = $this->security->getUser();

            // A logged-in user must belong to the company that owns this custom
            // domain: the Host header is attacker-controlled, so without this
            // check any authenticated user could switch their session's active
            // tenant to another company just by sending a different Host value.
            if ($user instanceof UserInterface) {
                assert($user instanceof User);

                if (! $user->getCompanies()->exists(static fn (int $key, Company $company): bool => $company->getId()->equals($companyId))) {
                    throw new AccessDeniedException('You do not have access to this company.');
                }
            }

            $this->companySelector->switchCompany($companyId);
            $session->set('company', $companyId);
            return;
        }

        if ($session->has('company')) {
            $companyId = $session->get('company');
            $user = $this->security->getUser();

            // The company remembered in the session is only reopened for someone
            // who still belongs to it. Taken off the company, a member kept full
            // access until they signed out; now their next page asks them to
            // pick one of the companies they are still in.
            if ($user instanceof User && $companyId instanceof Ulid && ! $user->getCompanies()->exists(static fn (int $key, Company $company): bool => $company->getId()->equals($companyId))) {
                $session->remove('company');

                if (! $this->isOnCompanySelectionRoute($request)) {
                    $event->setResponse(new RedirectResponse($this->router->generate('_select_company')));
                    $event->stopPropagation();
                }

                return;
            }

            $this->companySelector->switchCompany($companyId);
        } elseif (! $this->isOnCompanySelectionRoute($request) && ($user = $this->security->getUser()) instanceof UserInterface) {
            assert($user instanceof User);

            if (count($user->getCompanies()) === 1) {
                $this->companySelector->switchCompany($user->getCompanies()->first()->getId());
                $session->set('company', $user->getCompanies()->first()->getId());
                return;
            }

            $event->setResponse(new RedirectResponse($this->router->generate('_select_company')));
            $event->stopPropagation();
        }
    }

    private function isOnCompanySelectionRoute(Request $request): bool
    {
        if (! $this->isCompanyScoped($request)) {
            return true;
        }

        $routeName = $request->attributes->get('_route');

        return in_array(
            $routeName,
            [
                '2fa_login',
                '_select_company',
                '_switch_company',
                '_create_company',
                '_onboarding',
            ],
            true
        );
    }

    /**
     * Whether this route reads or writes one company's data.
     *
     * Nearly every route does, which is why the default is true and why a
     * route that does not has to say so, with `_company_scope` in its
     * defaults. The exceptions above are the company-selection flow itself;
     * this is for the other kind — a route that works *across* companies, or
     * none, for which being asked to pick one is not a step but a dead end:
     * a user who belongs to no company, or to several, would be redirected to
     * the picker on every request and could never arrive.
     */
    private function isCompanyScoped(Request $request): bool
    {
        return false !== $request->attributes->get('_company_scope', true);
    }
}
