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
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;
use function in_array;

/**
 * Leave, given by a company, for someone who is not one of its members to
 * come in and look — the people running the service, answering a request for
 * help.
 *
 * It is not a membership and does not pretend to be one: whoever holds it is
 * in the company under their own name, can only read, and only until the
 * time the company chose.
 */
final readonly class SupportPass
{
    /**
     * Routes that belong to no permission but that a visitor still needs:
     * somewhere to land, and the way out.
     */
    private const array OPEN_ROUTES = ['_dashboard', '_home', '_logout', '_logout_main'];

    /**
     * Live components a visitor may drive. A data grid only pages and sorts
     * what the visitor already may read, and checks its batch actions itself;
     * every other component either changes something or belongs to a person
     * (API tokens, two-factor), and a visitor has no business with either.
     */
    private const array COMPONENTS = ['DataGrid'];

    /**
     * Settings pages a visitor may look at, never save: what the company
     * configured is often the answer to what went wrong. Payment methods,
     * e-invoicing platforms and notification channels show which services
     * are set up; the window that holds the company's credentials with each
     * of them is not drawn for a visitor (see `support_visit()`).
     */
    private const array READABLE_SETTINGS = [
        '_einvoicing_providers',
        '_notification_integration',
        '_payment_settings_index',
        '_settings',
        '_settings_custom_fields',
        '_tax_rates',
        '_template_preview',
    ];

    /**
     * Live components a visitor may have re-rendered (switching a settings
     * tab) but never asked to act — saving is an action.
     */
    private const array RENDERED_COMPONENTS = [
        'ElectronicInvoiceMarketplace',
        'NotificationIntegrations',
        'NotificationMarketplace',
        'PaymentMarketplace',
        'Settings',
    ];

    /**
     * Set on a route that a visitor must reach although it grants nothing —
     * the page that ends the visit, typically.
     */
    public const string OPEN_ROUTE_ATTRIBUTE = '_support_open';

    public function __construct(
        public Ulid $requestId,
        public Ulid $companyId,
        public DateTimeImmutable $until,
    ) {
    }

    /**
     * Reading, and nothing else: no change, no export, no settings, no people.
     */
    public function can(CompanyPermission $permission): bool
    {
        return CompanyPermission::BillingRead === $permission;
    }

    /**
     * Whether a route may be reached on this pass. Unlike a member, whose
     * unclassified routes are open, a visitor is held to what is listed: a
     * route nobody thought about is closed.
     */
    public function allowsRoute(string $route, ?CompanyPermission $permission, bool $markedOpen = false, bool $safe = true): bool
    {
        if (CompanyPermission::Settings === $permission) {
            return $safe && in_array($route, self::READABLE_SETTINGS, true);
        }

        if ($permission instanceof CompanyPermission) {
            return $this->can($permission);
        }

        return $markedOpen || in_array($route, self::OPEN_ROUTES, true);
    }

    /**
     * @param string $action the live action asked for; "get" only re-renders
     */
    public function allowsComponent(string $component, string $action = 'get'): bool
    {
        if (in_array($component, self::COMPONENTS, true)) {
            return true;
        }

        return 'get' === $action && in_array($component, self::RENDERED_COMPONENTS, true);
    }
}
