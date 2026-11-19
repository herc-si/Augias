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

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Mcp\FinanceTools;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Mcp\InvoiceWriteTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Augias\PaymentBundle\Mcp\PaymentWriteTools;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The books through MCP, behind a scope of their own: an assistant allowed to
 * read and write documents does not, for that, read the company's figures.
 */
#[Group('functional')]
final class FinanceToolsTest extends KernelTestCase
{
    use SignsInMember;
    use EnsureApplicationInstalled;

    public function testReadAndWriteDoNotOpenTheBooks(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Write->value]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('mcp:finance');

        $this->tool()->getAccountingOverview();
    }

    public function testFinanceAloneStillReadsDocuments(): void
    {
        self::assertTrue(McpScope::satisfies([McpScope::Finance->value], McpScope::Read));
        self::assertFalse(McpScope::satisfies([McpScope::Write->value], McpScope::Finance));
        self::assertFalse(McpScope::satisfies([McpScope::Finance->value], McpScope::Write));
    }

    public function testAPaymentShowsInTheTurnoverAndTheBooks(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Write->value, McpScope::Finance->value]);
        $this->configureRegime();

        // The books' currency: a receipt in any other is left out of the turnover.
        $currency = self::getContainer()->get(SystemConfig::class)->getCurrency()->getCode();
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => $currency]);

        $invoices = self::getContainer()->get(InvoiceWriteTools::class);
        self::assertInstanceOf(InvoiceWriteTools::class, $invoices);
        $invoice = $invoices->createInvoice($client->getId()->toRfc4122(), [['description' => 'Mission', 'price' => 80000]]);
        $invoices->applyInvoiceTransition($invoice['id'], 'accept');

        $payments = self::getContainer()->get(PaymentWriteTools::class);
        self::assertInstanceOf(PaymentWriteTools::class, $payments);
        $payments->recordPayment($invoice['id'], 80000, $currency);

        $today = new DateTimeImmutable('today');

        $overview = $this->tool()->getAccountingOverview();
        self::assertSame(MicroEntrepriseRegime::CODE, $overview['regime']['code']);
        self::assertSame('80000', $overview['turnover_year_to_date']['total']);
        self::assertNotSame([], $overview['ceilings']);

        $turnover = $this->tool()->getTurnover($today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d'));
        self::assertSame('80000', $turnover['total']);

        $entries = $this->tool()->listLedgerEntries(book: 'revenue');
        self::assertSame(1, $entries['count']);
        self::assertSame('80000', $entries['results'][0]['amount']);

        self::assertSame(0, $this->tool()->listBankAccounts()['count']);

        try {
            $this->tool()->listLedgerEntries(book: 'nope');
            self::fail('An unknown book was accepted.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('revenue', $exception->getMessage());
        }
    }

    private function configureRegime(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);

        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    private function tool(): FinanceTools
    {
        $tool = self::getContainer()->get(FinanceTools::class);
        self::assertInstanceOf(FinanceTools::class, $tool);

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
