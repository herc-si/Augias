<?php

declare(strict_types=1);

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

    public function testOnlyTheDataGridAmongLiveComponents(): void
    {
        $pass = $this->pass();

        self::assertTrue($pass->allowsComponent('DataGrid'));
        self::assertFalse($pass->allowsComponent('CreateApiToken'));
        self::assertFalse($pass->allowsComponent('CreateInvoice'));
    }

    private function pass(): SupportPass
    {
        return new SupportPass(new Ulid(), new Ulid(), new DateTimeImmutable('+1 hour'));
    }
}
