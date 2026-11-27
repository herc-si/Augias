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

use Augias\CatalogBundle\Mcp\CatalogTools;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Mcp\CreditNoteTools;
use Augias\InvoiceBundle\Mcp\InvoiceWriteTools;
use Augias\McpBundle\Mcp\Tool\ResourceQueryTools;
use Augias\McpBundle\Security\McpOAuthAuthenticator;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Mcp\QuoteWriteTools;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Test\Factory\TaxFactory;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The selling side through MCP: the catalogue, sending, deposits and credit
 * notes, each held to the rules the screens apply.
 */
#[Group('functional')]
final class SalesToolsTest extends KernelTestCase
{
    use SignsInMember;
    use EnsureApplicationInstalled;

    public function testALineIsFilledFromTheCatalogue(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        $vat = $this->vat();

        $product = $this->catalog()->createProduct('Audit', 50000, unit: 'day', description: 'Sur site', tax_id: $vat->getId()->toRfc4122());
        self::assertSame('50000', $product['sale_price']);

        $found = $this->catalog()->searchProducts('aud');
        self::assertSame(1, $found['count']);

        $invoice = $this->invoices()->createInvoice($this->clientId(), [
            ['product_id' => $product['id'], 'qty' => 2],
        ]);

        $line = $invoice['lines'][0];
        self::assertSame("Audit\nSur site", $line['description']);
        self::assertSame('50000', $line['price']);
        self::assertSame('day', $line['unit']);
        self::assertSame('services', $line['supply_type']);
        self::assertCount(1, $line['taxes']);
        // 2 × 500,00 + 20 % VAT.
        self::assertSame('120000', $invoice['total']);
    }

    public function testADisbursementCarriesNoTaxAndStaysOffQuotes(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        $vat = $this->vat();

        $invoice = $this->invoices()->createInvoice($this->clientId(), [
            ['description' => 'Honoraires', 'price' => 10000],
            ['description' => 'Frais de greffe', 'price' => 3000, 'disbursement' => true],
            ['description' => 'Texte seul', 'note' => true],
        ]);

        self::assertFalse($invoice['lines'][0]['disbursement']);
        self::assertTrue($invoice['lines'][1]['disbursement']);
        self::assertTrue($invoice['lines'][2]['note']);
        self::assertSame('13000', $invoice['total']);

        try {
            $this->invoices()->createInvoice($this->clientId(), [
                ['description' => 'Frais', 'price' => 3000, 'disbursement' => true, 'tax_id' => $vat->getId()->toRfc4122()],
            ]);
            self::fail('A disbursement was given a tax.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('no tax', $exception->getMessage());
        }

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('disbursement');

        $this->quotes()->createQuote($this->clientId(), [
            ['description' => 'Frais', 'price' => 3000, 'disbursement' => true],
        ]);
    }

    public function testSendingAnInvoiceFinalisesAndEmailsIt(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        [$clientId, $contactId] = $this->clientWithContact();

        $draft = $this->invoices()->createInvoice($clientId, [['description' => 'Mission', 'price' => 10000]], contact_ids: [$contactId]);
        self::assertSame('', $draft['invoice_number']);

        $result = $this->invoices()->sendInvoice($draft['id']);

        self::assertTrue($result['email_sent']);
        self::assertSame('pending', $result['invoice']['status']);
        self::assertNotSame('', $result['invoice']['invoice_number']);
        self::assertEmailCount(1);
    }

    public function testAnInvoiceWithoutContactIsNotSent(): void
    {
        $this->activateScopes([McpScope::Write->value]);

        $draft = $this->invoices()->createInvoice($this->clientId(), [['description' => 'Mission', 'price' => 10000]]);

        self::assertNotNull($this->invoices()->sendingProblem($draft['id']), 'Asked before the person confirms.');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageIsOrContains('a contact with an e-mail address');

        $this->invoices()->sendInvoice($draft['id']);
    }

    /**
     * A quote made without a contact goes to its client's: « envoie-lui le
     * devis » was confirmed, failed, and asked again (Rémi, 27/11/2026).
     */
    public function testADocumentWithoutContactGoesToItsClientsContacts(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        [$clientId] = $this->clientWithContact();

        $quote = $this->quotes()->createQuote($clientId, [['description' => 'Site web', 'price' => 100000]]);
        self::assertNull($this->quotes()->sendingProblem($quote['id']));

        $this->quotes()->sendQuote($quote['id']);
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(0), 'To', 'compta@example.com');

        $invoice = $this->invoices()->createInvoice($clientId, [['description' => 'Mission', 'price' => 10000]]);
        self::assertNull($this->invoices()->sendingProblem($invoice['id']));
    }

    public function testADepositIsDeductedFromTheFinalInvoice(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        [$clientId, $contactId] = $this->clientWithContact();

        $quote = $this->quotes()->createQuote($clientId, [['description' => 'Site web', 'price' => 100000]], contact_ids: [$contactId]);
        $this->quotes()->sendQuote($quote['id']);
        $this->quotes()->applyQuoteTransition($quote['id'], 'accept');

        try {
            $this->quotes()->createDepositInvoice($quote['id'], 120);
            self::fail('A deposit of more than the quote was billed.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('at most 100', $exception->getMessage());
        }

        $deposit = $this->quotes()->createDepositInvoice($quote['id'], 30);
        self::assertSame('30000', $deposit['total']);

        // Only an issued deposit counts.
        $this->invoices()->applyInvoiceTransition($deposit['id'], 'accept');

        $final = $this->quotes()->convertQuoteToInvoice($quote['id']);
        self::assertSame('70000', $final['total']);
    }

    public function testACreditNoteCancelsAnInvoiceAndIsRefunded(): void
    {
        $this->activateScopes([McpScope::Write->value]);
        [$clientId, $contactId] = $this->clientWithContact();
        $vat = $this->vat();

        $invoice = $this->invoices()->createInvoice($clientId, [
            ['description' => 'Mission', 'price' => 10000, 'tax_id' => $vat->getId()->toRfc4122()],
        ], contact_ids: [$contactId]);
        $this->invoices()->applyInvoiceTransition($invoice['id'], 'accept');

        $creditNote = $this->creditNotes()->createCreditNote('cancellation', invoice_id: $invoice['id']);

        self::assertSame('draft', $creditNote['status']);
        // The VAT comes back with the line.
        self::assertSame('12000', $creditNote['total']);
        self::assertSame($invoice['id'], $creditNote['credited_invoice']['id']);

        $sent = $this->creditNotes()->sendCreditNote($creditNote['id']);
        self::assertTrue($sent['email_sent']);
        self::assertNotNull($sent['credit_note']['credit_note_number']);
        // Issued against an unpaid invoice, it is set against it at once.
        self::assertSame('settled', $sent['credit_note']['status']);
        self::assertSame('0', $sent['credit_note']['remaining']);
        self::assertSame('offset', $sent['credit_note']['allocations'][0]['kind']);

        try {
            $this->creditNotes()->createCreditNote('rebate', invoice_id: $invoice['id'], lines: [['description' => 'Geste', 'price' => 100]]);
            self::fail('More than the invoice was credited.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('more than what is left', $exception->getMessage());
        }

        // A gesture with no invoice behind it is paid back.
        $gesture = $this->creditNotes()->createCreditNote('commercial_gesture', client_id: $clientId, lines: [['description' => 'Geste commercial', 'price' => 5000]], contact_ids: [$contactId]);

        try {
            $this->creditNotes()->allocateCreditNote($gesture['id'], 'refund', 5000);
            self::fail('A draft credit note was refunded.');
        } catch (ToolCallException) {
        }

        $this->creditNotes()->issueCreditNote($gesture['id']);
        $refunded = $this->creditNotes()->allocateCreditNote($gesture['id'], 'refund', 5000, notes: 'Virement');
        self::assertSame('settled', $refunded['status']);
        self::assertSame('0', $refunded['remaining']);
        self::assertSame('refund', $refunded['allocations'][0]['kind']);

        $listed = self::getContainer()->get(ResourceQueryTools::class)->listResource('credit_note');
        self::assertSame(2, $listed['total']);
    }

    private function vat(): Tax
    {
        return TaxFactory::createOne([
            'name' => 'TVA',
            'rate' => 20.0,
            'type' => Tax::TYPE_EXCLUSIVE,
            'category' => TaxCategory::Standard,
            'company' => $this->company,
        ]);
    }

    private function clientId(): string
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        return $client->getId()->toRfc4122();
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

    private function catalog(): CatalogTools
    {
        $tool = self::getContainer()->get(CatalogTools::class);
        self::assertInstanceOf(CatalogTools::class, $tool);

        return $tool;
    }

    private function invoices(): InvoiceWriteTools
    {
        $tool = self::getContainer()->get(InvoiceWriteTools::class);
        self::assertInstanceOf(InvoiceWriteTools::class, $tool);

        return $tool;
    }

    private function quotes(): QuoteWriteTools
    {
        $tool = self::getContainer()->get(QuoteWriteTools::class);
        self::assertInstanceOf(QuoteWriteTools::class, $tool);

        return $tool;
    }

    private function creditNotes(): CreditNoteTools
    {
        $tool = self::getContainer()->get(CreditNoteTools::class);
        self::assertInstanceOf(CreditNoteTools::class, $tool);

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
