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

namespace Augias\UserBundle\Twig\Extension;

use Augias\UserBundle\Security\RouteAccess;
use Twig\Attribute\AsTwigFunction;

/**
 * `route_allowed('_invoices_create')`: whether a link would open for the
 * member's role, so a template can leave it out rather than lead to a refusal.
 */
final readonly class RouteAccessExtension
{
    public function __construct(
        private RouteAccess $routeAccess,
    ) {
    }

    #[AsTwigFunction('route_allowed')]
    public function allowed(string $route): bool
    {
        return $this->routeAccess->allows($route);
    }
}
