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

namespace Augias\McpBundle\Tests\Functional;

use Augias\AccountingBundle\Mcp\AccountingWriteTools;
use Augias\AccountingBundle\Mcp\FinanceTools;
use Augias\BillBundle\Mcp\PurchaseTools;
use Augias\CatalogBundle\Mcp\CatalogTools;
use Augias\ClientBundle\Mcp\ClientReadTools;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Mcp\CreditNoteTools;
use Augias\InvoiceBundle\Mcp\InvoiceReadTools;
use Augias\InvoiceBundle\Mcp\ReportTools;
use Augias\McpBundle\Mcp\Tool\DocumentEditTools;
use Augias\McpBundle\Mcp\Tool\MiscellaneousTools;
use Augias\McpBundle\Mcp\Tool\ResourceQueryTools;
use Augias\McpBundle\Mcp\Tool\WorkflowTools;
use Augias\PaymentBundle\Mcp\PaymentMethodReadTools;
use Augias\QuoteBundle\Mcp\QuoteReadTools;
use Augias\SettingsBundle\Mcp\SettingsReadTools;
use Augias\TaxBundle\Mcp\TaxReadTools;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts every Phase 2 tool class is registered with the `mcp.tool` tag in the
 * container. If one is missing, a business bundle is likely misconfigured.
 */
#[Group('functional')]
final class ToolRegistrationTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testAllReadToolClassesAreResolvable(): void
    {
        $expected = [
            ResourceQueryTools::class,
            WorkflowTools::class,
            InvoiceReadTools::class,
            QuoteReadTools::class,
            ClientReadTools::class,
            PaymentMethodReadTools::class,
            TaxReadTools::class,
            SettingsReadTools::class,
            CatalogTools::class,
            CreditNoteTools::class,
            PurchaseTools::class,
            FinanceTools::class,
            ReportTools::class,
            DocumentEditTools::class,
            MiscellaneousTools::class,
            AccountingWriteTools::class,
        ];

        foreach ($expected as $class) {
            $service = self::getContainer()->get($class);
            self::assertInstanceOf($class, $service, sprintf('Tool class %s is not resolvable.', $class));
        }
    }
}
