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

use Augias\BillBundle\Mcp\PurchaseTools;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Mcp\Tool\ResourceQueryTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Purchases through MCP: entered, confirmed, paid, and taken from an invoice
 * received on the platform.
 */
#[Group('functional')]
final class PurchaseToolsTest extends KernelTestCase
{
    use SignsInMember;
    use EnsureApplicationInstalled;

    public function testAPurchaseIsEnteredConfirmedAndPaid(): void
    {
        $this->activateScopes([McpScope::Write->value]);

        $bill = $this->tool()->createBill(12000, supplier_name: 'Imprimerie Dupont', tax_amount: 2000, bill_number: 'F-2026-118', issue_date: '2026-11-02', due_date: '2026-12-02');

        self::assertSame('draft', $bill['status']);
        self::assertSame('Imprimerie Dupont', $bill['supplier']['name']);
        self::assertSame('10000', $bill['net_amount']);
        self::assertSame('EUR', $bill['currency']);

        $supplier = self::getContainer()->get(ResourceQueryTools::class)->getResource('client', $bill['supplier']['id']);
        self::assertTrue($supplier['is_supplier']);
        self::assertFalse($supplier['is_client']);

        $bill = $this->tool()->applyBillTransition($bill['id'], 'confirm');
        self::assertSame('pending', $bill['status']);

        try {
            $this->tool()->recordBillPayment($bill['id'], 15000);
            self::fail('More than the purchase was paid.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('more than what is left', $exception->getMessage());
        }

        $bill = $this->tool()->recordBillPayment($bill['id'], 5000, '2026-11-10');
        self::assertSame('pending', $bill['status']);
        self::assertSame('7000', $bill['balance']);

        $bill = $this->tool()->recordBillPayment($bill['id'], 7000, '2026-11-20', 'check', 'CHQ 4521');
        self::assertSame('paid', $bill['status']);
        self::assertCount(2, $bill['payments']);

        $listed = self::getContainer()->get(ResourceQueryTools::class)->listResource('bill');
        self::assertSame(1, $listed['total']);
    }

    public function testAReceivedInvoiceBecomesOnePurchase(): void
    {
        $this->activateScopes([McpScope::Write->value]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $company = $em->getReference(Company::class, $this->company->getId());

        $receipt = new ElectronicInvoiceReceipt()
            ->setProvider('super_pdp')
            ->setExternalReference('ext-1')
            ->setInvoiceNumber('BQ-77')
            ->setSellerName('Burger Queen')
            ->setSellerIdentifier('000000002')
            ->setIssueDate(new DateTimeImmutable('2026-09-04'))
            ->setTotalAmount(BigInteger::of(5_000))
            ->setCurrencyCode('EUR');
        $receipt->setCompany($company);
        $em->persist($receipt);
        $em->flush();

        $received = $this->tool()->listReceivedInvoices(unanswered_only: true);
        self::assertSame(1, $received['count']);
        self::assertNull($received['results'][0]['bill_id']);

        $id = $receipt->getId()?->toRfc4122();
        self::assertNotNull($id);

        $bill = $this->tool()->createBillFromReceivedInvoice($id);
        self::assertSame('BQ-77', $bill['bill_number']);
        self::assertSame($id, $bill['received_invoice_id']);

        // Asked twice, the same purchase.
        self::assertSame($bill['id'], $this->tool()->createBillFromReceivedInvoice($id)['id']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('reason');

        $this->tool()->respondToReceivedInvoice($id, 'refused', 'NOT_A_CODE');
    }

    private function tool(): PurchaseTools
    {
        $tool = self::getContainer()->get(PurchaseTools::class);
        self::assertInstanceOf(PurchaseTools::class, $tool);

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
