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

namespace Augias\QuoteBundle\Mcp;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\InvoiceTaxBuilder;
use Augias\McpBundle\Mcp\Tool\LineItemBuilder;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Augias\QuoteBundle\Cloner\QuoteCloner;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Exception\InvalidTransitionException;
use Augias\QuoteBundle\Model\Graph as QuoteGraph;
use Augias\QuoteBundle\Repository\QuoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Augias\InvoiceBundle\Deposit\DepositBilling;
use Augias\McpBundle\Mcp\Tool\PlanLimits;
use Augias\QuoteBundle\Mailer\QuoteMailer;
use Augias\SaasBundle\Feature\Feature;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class QuoteWriteTools
{
    public function __construct(
        private QuoteRepository $quoteRepository,
        private ClientRepository $clientRepository,
        private QuoteCloner $cloner,
        private InvoiceManager $invoiceManager,
        private LineItemBuilder $lineItemBuilder,
        private InvoiceTaxBuilder $invoiceTaxBuilder,
        private TotalCalculator $totalCalculator,
        private BillingIdGenerator $billingIdGenerator,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        #[Autowire(service: 'state_machine.quote')]
        private WorkflowInterface $quoteWorkflow,
        private McpScopeGuard $scopeGuard,
        private PlanLimits $planLimits,
        private DepositBilling $deposits,
        private QuoteMailer $quoteMailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new quote for an existing client.
     *
     * Totals are calculated server-side from the line items. The quote starts
     * as a draft — use `apply_quote_transition` to send/accept/etc.
     *
     * @param string                          $client_id      Client ULID (must belong to the active company)
     * @param list<array<string, mixed>>      $lines          Line items: [{description, price, qty, supply_type?: goods|services, tax_id?, taxes?: [{tax_id, sequence?, compound?}]}, ...].
     *                                                        Provide `taxes[]` for multi-tax lines (India GST, Quebec compound, etc.);
     *                                                        `tax_id` is the legacy single-tax shorthand.
     * @param string|null                     $due            ISO-8601 due date (optional)
     * @param string|null                     $discount_type  "percentage" or "money" (optional)
     * @param int|float|null                  $discount_value Numeric discount value; required if discount_type is set
     * @param string|null                     $terms          Optional terms text
     * @param string|null                     $notes          Optional notes text
     * @param string|null                     $subject        Optional subject (what the document is about), printed above the lines
     * @param list<string>                    $contact_ids    Client contact ULIDs to attach (optional)
     * @param string|null                     $quote_id       Explicit quote number (generated if omitted)
     * @param list<array<string, mixed>>      $invoice_taxes  Invoice-level taxes (withholding/surcharge/informational):
     *                                                        [{tax_id, direction: Additive|Deductive|Informational, sequence?, note?}].
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_quote', description: 'Create a new quote for a client, with line items, optional subject, discount, due date, contacts, and invoice-level taxes.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createQuote(
        string $client_id,
        array $lines,
        ?string $due = null,
        ?string $discount_type = null,
        int | float | null $discount_value = null,
        ?string $terms = null,
        ?string $notes = null,
        ?string $subject = null,
        array $contact_ids = [],
        ?string $quote_id = null,
        array $invoice_taxes = [],
    ): array {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertFeature(Feature::Quotes, 'Quotes');

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $quote = new Quote();
        $quote->setClient($client);

        if ($due !== null) {
            try {
                $quote->setDue(new DateTimeImmutable($due));
            } catch (Exception) {
                throw new ToolCallException(sprintf('Invalid due date "%s". Use ISO-8601 format (YYYY-MM-DD).', $due));
            }
        }

        foreach ($this->lineItemBuilder->buildQuoteLines($lines) as $line) {
            $quote->addLine($line);
        }

        $quote->setDiscount($this->lineItemBuilder->buildDiscount($discount_type, $discount_value));

        if ($terms !== null) {
            $quote->setTerms($terms);
        }

        if ($notes !== null) {
            $quote->setNotes($notes);
        }

        $quote->setSubject($subject);

        foreach ($contact_ids as $index => $contactId) {
            if (! \is_string($contactId)) {
                throw new ToolCallException(sprintf('contact_ids[%d] must be a string.', $index));
            }

            $contact = $this->entityManager
                ->getRepository(Contact::class)
                ->find(UlidParser::parse($contactId, sprintf('contact_ids[%d]', $index)));

            if (! $contact instanceof Contact || $contact->getClient()?->getId()?->equals($client->getId()) !== true) {
                throw new ToolCallException(sprintf('Contact %s does not belong to this client.', $contactId));
            }

            $quote->addUser($contact);
        }

        $quote->setQuoteId(
            $quote_id !== null && $quote_id !== ''
                ? $quote_id
                : $this->billingIdGenerator->generate($quote, ['field' => 'quoteId']),
        );

        $this->invoiceTaxBuilder->attach($quote, $invoice_taxes);

        $this->totalCalculator->calculateTotals($quote);

        // Seed the status so the NOT NULL column is satisfied before the workflow
        // apply() moves it to the actual initial "new" state.
        $quote->setStatus(QuoteStatus::New);
        $this->entityManager->persist($quote);
        $this->entityManager->flush();

        if ($this->quoteWorkflow->can($quote, QuoteGraph::TRANSITION_NEW)) {
            $this->quoteWorkflow->apply($quote, QuoteGraph::TRANSITION_NEW);
            $this->entityManager->flush();
        }

        return $this->normalizer->normalize($quote);
    }

    /**
     * Apply a workflow transition to a quote.
     *
     * @param string $quote_id   Quote ULID
     * @param string $transition One of: send, accept, decline, cancel, reopen, archive
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'apply_quote_transition', description: 'Apply a workflow transition (send, accept, decline, cancel, reopen, archive) to a quote.')]
    #[McpScopeRequired(McpScope::Write)]
    public function applyQuoteTransition(string $quote_id, string $transition): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $quote = $this->quoteRepository->find(UlidParser::parse($quote_id, 'quote_id'));

        if (! $quote instanceof Quote) {
            throw new ToolCallException(sprintf('Quote %s not found.', $quote_id));
        }

        if (! $this->quoteWorkflow->can($quote, $transition)) {
            throw new ToolCallException(sprintf(
                'Transition "%s" is not enabled for quote in status "%s".',
                $transition,
                $quote->getStatus()?->value ?? 'unknown',
            ));
        }

        $this->quoteWorkflow->apply($quote, $transition);
        $this->entityManager->flush();

        return $this->normalizer->normalize($quote);
    }

    /**
     * Clone an existing quote into a new draft.
     *
     * @param string $quote_id Quote ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'clone_quote', description: 'Clone an existing quote into a new draft with the same line items.')]
    #[McpScopeRequired(McpScope::Write)]
    public function cloneQuote(string $quote_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertFeature(Feature::Quotes, 'Quotes');

        $quote = $this->quoteRepository->find(UlidParser::parse($quote_id, 'quote_id'));

        if (! $quote instanceof Quote) {
            throw new ToolCallException(sprintf('Quote %s not found.', $quote_id));
        }

        $cloned = $this->cloner->clone($quote);

        // QuoteCloner::clone() only builds the entity and applies the workflow
        // transition; it does not persist or flush. Persist here so the MCP
        // caller gets a saved entity (and a real id in the response).
        $this->entityManager->persist($cloned);
        $this->entityManager->flush();

        return $this->normalizer->normalize($cloned);
    }

    /**
     * Convert an accepted quote into a new invoice.
     *
     * @param string $quote_id Quote ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'convert_quote_to_invoice', description: 'Convert a quote into a new invoice, copying client, line items, and totals.')]
    #[McpScopeRequired(McpScope::Write)]
    public function convertQuoteToInvoice(string $quote_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertCanCreateInvoice();

        $quote = $this->quoteRepository->find(UlidParser::parse($quote_id, 'quote_id'));

        if (! $quote instanceof Quote) {
            throw new ToolCallException(sprintf('Quote %s not found.', $quote_id));
        }

        if ($quote->getInvoice() instanceof Invoice) {
            throw new ToolCallException('This quote has already been converted to an invoice.');
        }

        if ($quote->getStatus() !== QuoteStatus::Accepted) {
            throw new ToolCallException(sprintf(
                'Only accepted quotes can be converted to an invoice. Current status: "%s".',
                $quote->getStatus()?->value ?? 'unknown',
            ));
        }

        $invoice = $this->invoiceManager->createFromQuote($quote);
        // What deposit invoices already billed comes off, as on the screen:
        // the final invoice is for the rest.
        $this->deposits->deduct($quote, $invoice);
        $invoice = $this->invoiceManager->create($invoice);

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Bill a share of an accepted quote ahead of the work, as a deposit invoice
     * (facture d'acompte). The final invoice made later from the quote deducts
     * every deposit issued.
     *
     * @param string     $quote_id   Quote ULID; the quote must be accepted and not yet invoiced
     * @param int|float|string $percentage Share of the quote to bill, in percent (e.g. 30)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_deposit_invoice', description: 'Create a deposit invoice (facture d\'acompte) for a percentage of an accepted quote. The final invoice from convert_quote_to_invoice deducts it.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createDepositInvoice(string $quote_id, int | float | string $percentage): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $quote = $this->quoteRepository->find(UlidParser::parse($quote_id, 'quote_id'));

        if (! $quote instanceof Quote) {
            throw new ToolCallException(sprintf('Quote %s not found.', $quote_id));
        }

        if ($quote->getStatus() !== QuoteStatus::Accepted) {
            throw new ToolCallException('Only an accepted quote can be billed a deposit.');
        }

        if ($quote->getInvoice() instanceof Invoice) {
            throw new ToolCallException('The final invoice of this quote exists already: everything left has been billed.');
        }

        try {
            $share = BigDecimal::of(\is_float($percentage) ? sprintf('%.6F', $percentage) : str_replace(',', '.', trim((string) $percentage)));
        } catch (MathException) {
            throw new ToolCallException(sprintf('Invalid percentage "%s".', (string) $percentage));
        }

        $remaining = $this->deposits->remainingPercentage($quote);

        if (! $share->isPositive() || $share->isGreaterThan($remaining)) {
            throw new ToolCallException(sprintf('The percentage must be above 0 and at most %s, the share of the quote not billed yet.', (string) $remaining->strippedOfTrailingZeros()));
        }

        $this->planLimits->assertCanCreateInvoice();

        $invoice = $this->deposits->draft($quote, $share);
        $invoice->setDepositForQuote($quote);

        foreach ($invoice->getUsers() as $contact) {
            $contact->addInvoice($invoice);
        }

        $invoice->setInvoiceId('');
        $invoice = $this->invoiceManager->create($invoice);

        return $this->normalizer->normalize($invoice);
    }

    /**
     * Email a quote to its contacts. A draft is marked as sent first.
     *
     * @param string $quote_id Quote ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'send_quote', description: 'Email a quote (with its PDF) to its contacts; a draft quote is marked as sent.')]
    #[McpScopeRequired(McpScope::Write)]
    public function sendQuote(string $quote_id): array
    {
        $this->scopeGuard->require(McpScope::Write);
        $this->planLimits->assertCanSend();

        $quote = $this->quoteRepository->find(UlidParser::parse($quote_id, 'quote_id'));

        if (! $quote instanceof Quote) {
            throw new ToolCallException(sprintf('Quote %s not found.', $quote_id));
        }

        if ($quote->getUsers()->isEmpty()) {
            throw new ToolCallException('The quote has no contact to send it to. Add a contact to the client, then to the quote.');
        }

        try {
            $this->quoteMailer->send($quote);
        } catch (InvalidTransitionException) {
            throw new ToolCallException(sprintf('A quote in status "%s" cannot be sent.', $quote->getStatus()->value));
        } catch (TransportExceptionInterface | JsonException $exception) {
            $this->logger->error('Failed to send quote via MCP', ['quote_id' => $quote->getQuoteId(), 'exception' => $exception]);

            throw new ToolCallException('Failed to send the quote. See server logs for details.', 0, $exception);
        }

        $this->entityManager->flush();

        return $this->normalizer->normalize($quote);
    }
}
