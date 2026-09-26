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

use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Security\RouteAccess;
use DateTimeImmutable;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `route_allowed('_invoices_create')`: whether a link would open for the
 * member's role, so a template can leave it out rather than lead to a refusal.
 */
final readonly class RouteAccessExtension
{
    public function __construct(
        private RouteAccess $routeAccess,
        private CompanyAccess $companyAccess,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsTwigFunction('route_allowed')]
    public function allowed(string $route): bool
    {
        return $this->routeAccess->allows($route);
    }

    /**
     * `company_closes_at()`: when the open company goes, if it is closing —
     * for the banner every page then carries.
     */
    #[AsTwigFunction('company_closes_at')]
    public function closesAt(): ?DateTimeImmutable
    {
        return $this->companyAccess->membership()?->getCompany()->getClosesAt();
    }

    /**
     * `company_closure_reason()`: asked for by the owner, or following the
     * end of the subscription — the banner offers to cancel the one, to
     * renew for the other.
     */
    #[AsTwigFunction('company_closure_reason')]
    public function closureReason(): ?string
    {
        return $this->companyAccess->membership()?->getCompany()->getClosureReason()?->value;
    }

    /**
     * `optional_path('saas_subscription_plans')`: the link, or null where the
     * route is not loaded — the hosted service's pages exist only there.
     */
    #[AsTwigFunction('optional_path')]
    public function optionalPath(string $route): ?string
    {
        try {
            return $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
