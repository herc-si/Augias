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

namespace Augias\UserBundle\Tests\Security;

use Augias\UserBundle\Enum\CompanyPermission;
use Augias\UserBundle\Security\SupportPass;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class SupportPassTest extends TestCase
{
    public function testAVisitorReadsAndNothingElse(): void
    {
        $pass = $this->pass();

        self::assertTrue($pass->can(CompanyPermission::BillingRead));

        foreach (CompanyPermission::cases() as $permission) {
            if (CompanyPermission::BillingRead !== $permission) {
                self::assertFalse($pass->can($permission), $permission->value);
            }
        }
    }

    /**
     * A member's unclassified route is open; a visitor's is closed. Only the
     * landing page, signing out and routes marked open let them through.
     */
    public function testARouteNobodyClassifiedIsClosedToAVisitor(): void
    {
        $pass = $this->pass();

        self::assertFalse($pass->allowsRoute('_profile', null));
        self::assertFalse($pass->allowsRoute('saas_subscription_change', null));
        self::assertTrue($pass->allowsRoute('_dashboard', null));
        self::assertTrue($pass->allowsRoute('_logout', null));
        self::assertTrue($pass->allowsRoute('_support_leave', null, true));
        self::assertTrue($pass->allowsRoute('_clients_view', CompanyPermission::BillingRead));
        self::assertFalse($pass->allowsRoute('_clients_edit', CompanyPermission::BillingWrite));
    }

    public function testSettingsAreReadNeverSavedAndCredentialsStayClosed(): void
    {
        $pass = $this->pass();

        self::assertTrue($pass->allowsRoute('_settings', CompanyPermission::Settings));
        self::assertTrue($pass->allowsRoute('_tax_rates', CompanyPermission::Settings));
        self::assertFalse($pass->allowsRoute('_settings', CompanyPermission::Settings, false, false), 'Not a POST.');
        self::assertTrue($pass->allowsRoute('_payment_settings_index', CompanyPermission::Settings));
        self::assertTrue($pass->allowsRoute('_einvoicing_providers', CompanyPermission::Settings));
        self::assertTrue($pass->allowsRoute('_notification_integration', CompanyPermission::Settings));
        self::assertFalse($pass->allowsRoute('_notification_integration', CompanyPermission::Settings, false, false), 'Not a POST.');
        self::assertFalse($pass->allowsRoute('_api_keys_index', null), 'API tokens belong to a person.');
        self::assertFalse($pass->can(CompanyPermission::Settings), 'No save button is offered.');
    }

    public function testOnlyTheDataGridAmongLiveComponentsAndSettingsRenderedNotSaved(): void
    {
        $pass = $this->pass();

        self::assertTrue($pass->allowsComponent('DataGrid'));
        self::assertTrue($pass->allowsComponent('Settings', 'get'));
        self::assertFalse($pass->allowsComponent('Settings', 'save'));
        self::assertFalse($pass->allowsComponent('Settings', '_batch'));
        self::assertTrue($pass->allowsComponent('PaymentMarketplace', 'get'));
        self::assertFalse($pass->allowsComponent('PaymentSettings', 'get'), 'The credentials window is never live for a visitor.');
        self::assertFalse($pass->allowsComponent('PaymentMarketplace', 'closeModal'));
        self::assertFalse($pass->allowsComponent('CreateApiToken'));
        self::assertFalse($pass->allowsComponent('CreateInvoice'));
    }

    private function pass(): SupportPass
    {
        return new SupportPass(new Ulid(), new Ulid(), new DateTimeImmutable('+1 hour'));
    }
}
