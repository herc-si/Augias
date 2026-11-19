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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Mcp\InvoiceWriteTools;
use Augias\InvoiceBundle\Mcp\ReportTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Augias\PaymentBundle\Mcp\PaymentWriteTools;
use DateTimeImmutable;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Reports page through MCP: behind mcp:finance, as the books are.
 */
#[Group('functional')]
final class ReportToolsTest extends KernelTestCase
{
    use SignsInMember;
    use EnsureApplicationInstalled;

    public function testReportsNeedTheFinanceScope(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Write->value]);

        // Documents can still be followed.
        self::assertSame(0, $this->tool()->listEinvoiceSubmissions()['count']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('mcp:finance');

        $this->tool()->getAgedReceivables();
    }

    public function testWhatAClientOwesAndPaid(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Write->value, McpScope::Finance->value]);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'name' => 'Atelier Martin']);
        $clientId = $client->getId()->toRfc4122();

        $invoices = self::getContainer()->get(InvoiceWriteTools::class);
        self::assertInstanceOf(InvoiceWriteTools::class, $invoices);

        // 45 days late.
        $late = $invoices->createInvoice($clientId, [['description' => 'Mission', 'price' => 30000]], due: new DateTimeImmutable('-45 days')->format('Y-m-d'));
        $invoices->applyInvoiceTransition($late['id'], 'accept');

        $paid = $invoices->createInvoice($clientId, [['description' => 'Audit', 'price' => 20000]]);
        $invoices->applyInvoiceTransition($paid['id'], 'accept');

        $payments = self::getContainer()->get(PaymentWriteTools::class);
        self::assertInstanceOf(PaymentWriteTools::class, $payments);
        $payments->recordPayment($paid['id'], 20000, 'EUR');

        $aged = $this->tool()->getAgedReceivables()['by_currency']['EUR'];
        self::assertSame('30000', $aged['total']);
        self::assertSame('30000', $aged['totals']['31_60_days']);
        self::assertSame('0', $aged['totals']['not_due']);
        self::assertSame(['not_due', '1_30_days', '31_60_days', '61_90_days', 'over_90_days'], array_keys($aged['totals']));

        $statement = $this->tool()->getClientStatement($clientId);
        self::assertSame('30000', $statement['closing_balance']);
        self::assertCount(3, $statement['lines']);

        $revenue = $this->tool()->getRevenueByClient()['by_currency']['EUR'];
        self::assertSame('50000', $revenue['invoiced']);
        self::assertSame('20000', $revenue['received']);
        self::assertSame('Atelier Martin', $revenue['clients'][0]['client']);
    }

    private function tool(): ReportTools
    {
        $tool = self::getContainer()->get(ReportTools::class);
        self::assertInstanceOf(ReportTools::class, $tool);

        return $tool;
    }

    /**
     * @param list<string> $scopes
     */
    private function activateScopes(array $scopes): void
    {
        $container = self::getContainer();

        $stack = $container->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $stack);

        while ($stack->getMainRequest() instanceof Request) {
            $stack->pop();
        }

        $request = new Request();
        $request->attributes->set(McpOAuthAuthenticator::ATTR_SCOPES, $scopes);

        $stack->push($request);

        $selector = $container->get(CompanySelector::class);
        self::assertInstanceOf(CompanySelector::class, $selector);
        $selector->switchCompany($this->company->getId());
        $this->signInMember();
    }
}
