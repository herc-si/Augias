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

namespace Augias\UserBundle\Listener;

use Augias\TaxBundle\Entity\Tax;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Security\RoleDoesNotAllow;
use Augias\UserBundle\Security\RoutePermissionMap;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use function is_string;
use function str_starts_with;

/**
 * Holds every request to the member's role in the open company.
 *
 * One place, before any controller runs, rather than a check in each of the
 * hundred-odd actions: the screens, their live components and the API all
 * pass here. The buttons a role cannot use are hidden as well, but that is a
 * courtesy — this is what makes it so.
 *
 * @see \Augias\UserBundle\Tests\Listener\EnforceCompanyRoleListenerTest
 */
final readonly class EnforceCompanyRoleListener
{
    public function __construct(
        private RoutePermissionMap $map,
        private CompanyAccess $access,
        private Security $security,
        private TranslatorInterface $translator,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        if (! $event->isMainRequest() || ! $this->security->getUser() instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $permission = $this->required($request);

        if (! $permission instanceof CompanyPermission || $this->access->can($permission)) {
            return;
        }

        // Closing, not the role, is then what stands in the way — and what the
        // member is told.
        $closesAt = $this->access->membership()?->getCompany()->getClosesAt();

        if ($closesAt instanceof DateTimeImmutable) {
            throw new RoleDoesNotAllow($this->translator->trans('company.closing.read_only', [
                '%date%' => $closesAt->format('d/m/Y'),
            ]));
        }

        $role = $this->access->role();

        throw new RoleDoesNotAllow($this->translator->trans('users.permission_denied.' . $permission->value, [
            '%role%' => null === $role ? '—' : $this->translator->trans($role->labelKey()),
        ]));
    }

    private function required(Request $request): ?CompanyPermission
    {
        $route = $request->attributes->get('_route');

        if (! is_string($route)) {
            return null;
        }

        if (RoutePermissionMap::LIVE_COMPONENT_ROUTE === $route) {
            $component = $request->attributes->get('_live_component');

            return is_string($component) ? $this->map->forComponent($component) : null;
        }

        if (str_starts_with($route, '_api_')) {
            if ($request->isMethodSafe()) {
                return CompanyPermission::BillingRead;
            }

            // Tax rates are company settings wherever they are changed from.
            return Tax::class === $request->attributes->get('_api_resource_class')
                ? CompanyPermission::Settings
                : CompanyPermission::BillingWrite;
        }

        return $this->map->forRoute($route);
    }
}
