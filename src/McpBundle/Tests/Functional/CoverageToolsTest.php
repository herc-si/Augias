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
use Augias\AccountingBundle\Mcp\AccountingWriteTools;
use Augias\AccountingBundle\Mcp\FinanceTools;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\BillBundle\Mcp\PurchaseTools;
use Augias\CatalogBundle\Mcp\CatalogTools;
use Augias\ClientBundle\Mcp\ClientWriteTools;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Mcp\CreditNoteTools;
use Augias\InvoiceBundle\Mcp\InvoiceWriteTools;
use Augias\McpBundle\Mcp\Tool\DocumentEditTools;
use Augias\McpBundle\Mcp\Tool\MiscellaneousTools;
use Augias\McpBundle\Mcp\Tool\ResourceQueryTools;
use Augias\McpBundle\Mcp\Tool\ResourceWriteTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Mcp\QuoteWriteTools;
use Augias\SettingsBundle\SystemConfig;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the screens do and MCP now does too: categories, changing and deleting
 * drafts, addresses, the books written by hand, search and links.
 */
#[Group('functional')]
final class CoverageToolsTest extends KernelTestCase
{
    use SignsInMember;
    use EnsureApplicationInstalled;

    public function testAProductMovesToACategory(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        $catalog = $this->tool(CatalogTools::class);

        $product = $catalog->createProduct('Audit', 50000);
        $purchases = $catalog->createCategory('Fournitures', ['purchase']);

        try {
            $catalog->updateProduct($product['id'], ['category_id' => $purchases['id']]);
            self::fail('A purchase-only category was put on a product.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('update_category', $exception->getMessage());
        }

        $category = $catalog->createCategory('Conseil');
        $updated = $catalog->updateProduct($product['id'], ['category_id' => $category['id'], 'name' => 'Audit sécurité']);

        self::assertSame($category['id'], $updated['category']['id']);
        self::assertSame('Audit sécurité', $updated['name']);
        self::assertEqualsCanonicalizing(['catalog', 'purchase'], $catalog->updateCategory($purchases['id'], usages: ['catalog', 'purchase'])['usages']);
        self::assertSame(1, $catalog->listCategories('purchase')['count']);

        self::assertTrue($catalog->deleteProduct($product['id'])['deleted']);
    }

    public function testADraftChangesAndAnIssuedInvoiceDoesNot(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        [$clientId, $contactId] = $this->clientWithContact();

        $invoices = $this->tool(InvoiceWriteTools::class);
        $edit = $this->tool(DocumentEditTools::class);

        $draft = $invoices->createInvoice($clientId, [['description' => 'Mission', 'price' => 10000]]);
        $changed = $edit->updateInvoice($draft['id'], [
            'subject' => 'Refonte',
            'due' => '2026-12-31',
            'lines' => [['description' => 'Mission', 'price' => 10000, 'qty' => 3]],
            'contact_ids' => [$contactId],
        ]);

        self::assertSame('Refonte', $changed['subject']);
        self::assertSame('30000', $changed['total']);
        self::assertCount(1, $changed['lines']);

        try {
            $edit->updateInvoice($draft['id'], ['colour' => 'blue']);
            self::fail('An unknown field was accepted.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('colour', $exception->getMessage());
        }

        $invoices->applyInvoiceTransition($draft['id'], 'accept');

        try {
            $edit->updateInvoice($draft['id'], ['subject' => 'Autre']);
            self::fail('An issued invoice was changed.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('create_credit_note', $exception->getMessage());
        }

        try {
            $edit->deleteDocument('invoice', $draft['id']);
            self::fail('An issued invoice was deleted.');
        } catch (ToolCallException) {
        }

        $other = $invoices->createInvoice($clientId, [['description' => 'Brouillon', 'price' => 100]]);
        self::assertTrue($edit->deleteDocument('invoice', $other['id'])['deleted']);

        $quote = $this->tool(QuoteWriteTools::class)->createQuote($clientId, [['description' => 'Devis', 'price' => 5000]]);
        self::assertSame('Nouveau sujet', $edit->updateQuote($quote['id'], ['subject' => 'Nouveau sujet'])['subject']);

        $creditNote = $this->tool(CreditNoteTools::class)->createCreditNote('cancellation', invoice_id: $draft['id']);
        self::assertSame('return', $edit->updateCreditNote($creditNote['id'], ['reason' => 'return'])['reason']);

        $bill = $this->tool(PurchaseTools::class)->createBill(6000, supplier_name: 'Papeterie');
        self::assertSame('F-9', $edit->updateBill($bill['id'], ['bill_number' => 'F-9', 'total_amount' => 7200])['bill_number']);
    }

    public function testASupplierWithAnAddress(): void
    {
        $this->activateScopes([McpScope::Write->value]);

        $supplier = $this->tool(ResourceWriteTools::class)->createResource('client', [
            'name' => 'Imprimerie Dupont',
            'currency_code' => 'EUR',
            'siret' => '12345678900012',
            'is_client' => false,
            'is_supplier' => true,
        ]);
        self::assertTrue($supplier['is_supplier']);
        self::assertFalse($supplier['is_client']);

        $clients = $this->tool(ClientWriteTools::class);
        $withAddress = $clients->addClientAddress($supplier['id'], '12 rue des Lilas', 'Lyon', '69003');
        self::assertSame('FR', $withAddress['addresses'][0]['country']);

        $moved = $clients->updateClientAddress($withAddress['addresses'][0]['id'], ['city' => 'Villeurbanne', 'zip' => '69100']);
        self::assertSame('Villeurbanne', $moved['addresses'][0]['city']);

        self::assertSame([], $clients->removeClientAddress($withAddress['addresses'][0]['id'])['addresses']);

        $found = $this->tool(MiscellaneousTools::class)->search('dupont');
        self::assertSame($supplier['id'], $found['client'][0]['id']);

        $filtered = $this->tool(ResourceQueryTools::class)->listResource('client', ['is_supplier' => true]);
        self::assertSame(1, $filtered['total']);
    }

    public function testAnEntryByHandAndTheLinks(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Write->value, McpScope::Finance->value]);

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);

        $books = $this->tool(AccountingWriteTools::class);

        try {
            $books->addLedgerEntry('sales', 'today', 'Vente', 'Client', 1000);
            self::fail('An entry was written in a book the application keeps.');
        } catch (ToolCallException) {
        }

        $entry = $books->addLedgerEntry('revenue', 'today', 'Vente au comptoir', 'Client de passage', 4500, 'cash');
        self::assertSame('4500', $entry['amount']);

        $listed = $this->tool(FinanceTools::class)->listLedgerEntries(book: 'revenue');
        self::assertSame(1, $listed['count']);

        self::assertTrue($books->deleteLedgerEntry($entry['id'])['deleted']);

        [$clientId] = $this->clientWithContact();
        $invoice = $this->tool(InvoiceWriteTools::class)->createInvoice($clientId, [['description' => 'Mission', 'price' => 10000]]);
        $links = $this->tool(MiscellaneousTools::class)->getDocumentLinks('invoice', $invoice['id']);
        self::assertStringEndsWith('.pdf', (string) $links['pdf_url']);
        self::assertStringContainsString('/view/invoice/', (string) $links['client_url']);
    }

    public function testTheBooksAreNotWrittenWithoutBothScopes(): void
    {
        $this->activateScopes([McpScope::Read->value, McpScope::Finance->value]);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('mcp:write');

        $this->tool(AccountingWriteTools::class)->addLedgerEntry('revenue', 'today', 'Vente', 'Client', 1000);
    }

    /**
     * @return array{string, string}
     */
    private function clientWithContact(): array
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $contact = ContactFactory::createOne(['company' => $this->company, 'client' => $client, 'email' => 'compta@example.com']);

        return [$client->getId()->toRfc4122(), $contact->getId()->toRfc4122()];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): object
    {
        $tool = self::getContainer()->get($class);
        self::assertInstanceOf($class, $tool);

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
