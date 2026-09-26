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

namespace Augias\UserBundle\Security;

use Augias\UserBundle\Enum\CompanyPermission;

/**
 * Whether a route is open to the member's role — for whatever offers a link
 * to it, so that nothing is offered which would end on a refusal.
 */
final readonly class RouteAccess
{
    public function __construct(
        private RoutePermissionMap $map,
        private CompanyAccess $access,
    ) {
    }

    /**
     * Outside a member's session — no one signed in, no company open — there
     * is no role to hold anything to, and nothing is taken away.
     */
    public function allows(string $route): bool
    {
        $permission = $this->map->forRoute($route);

        if (! $permission instanceof CompanyPermission || null === $this->access->role()) {
            return true;
        }

        return $this->access->can($permission);
    }
}
