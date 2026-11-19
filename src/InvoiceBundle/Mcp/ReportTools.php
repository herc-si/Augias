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

namespace Augias\InvoiceBundle\Mcp;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\InvoiceBundle\Report\AgedReceivables;
use Augias\InvoiceBundle\Report\ClientStatement;
use Augias\InvoiceBundle\Report\RevenueByClient;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Clock\ClockInterface;

/**
 * The reports of the Reports page, and how the electronic invoices sent are
 * doing. The reports add up what clients owe and what was earned, so they
 * need the mcp:finance scope; the e-invoicing follow-up is about documents
 * and needs only mcp:read.
 *
 * Amounts are in the minor unit (cents), grouped by currency.
 */
final readonly class ReportTools
{
    public function __construct(
        private AgedReceivables $agedReceivables,
        private ClientStatement $clientStatement,
        private RevenueByClient $revenueByClient,
        private ClientRepository $clientRepository,
        private ElectronicInvoiceSubmissionRepository $submissionRepository,
        private ClockInterface $clock,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * What each client still owes, by how late it is: not due yet, 1-30 days,
     * 31-60, 61-90 and over 90 days past the due date.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_aged_receivables', description: 'Aged receivables (balance âgée): what each client owes, split into not due, 1-30, 31-60, 61-90 and over 90 days late, by currency. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function getAgedReceivables(): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $labels = $this->bucketLabels();
        $currencies = [];

        foreach ($this->agedReceivables->asOf(DateTimeImmutable::createFromInterface($this->clock->now())) as $currency => $table) {
            $rows = [];

            foreach ($table['rows'] as $row) {
                $rows[] = [
                    'client_id' => $row['clientId'],
                    'client' => $row['client'],
                    'buckets' => $this->buckets($labels, $row['buckets']),
                    'total' => (string) $row['total'],
                ];
            }

            $currencies[$currency] = [
                'clients' => $rows,
                'totals' => $this->buckets($labels, $table['totals']),
                'total' => (string) $table['total'],
            ];
        }

        return ['as_of' => $this->clock->now()->format('Y-m-d'), 'by_currency' => $currencies];
    }

    /**
     * A client's account: invoices, payments and credit notes in date order,
     * with the running balance.
     *
     * @param string      $client_id Client ULID
     * @param string|null $from      ISO-8601 first day shown; earlier movements make up the opening balance
     * @param string|null $to        ISO-8601 last day shown
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_client_statement', description: 'Statement of account for a client (relevé de compte): invoices, payments and credit notes in date order with the running balance, optionally between two dates. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function getClientStatement(string $client_id, ?string $from = null, ?string $to = null): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $statement = $this->clientStatement->forClient(
            $client,
            $from !== null ? $this->date($from) : null,
            $to !== null ? $this->date($to) : null,
        );

        return [
            'client' => ['id' => $client_id, 'name' => $client->getName(), 'currency' => $client->getCurrencyCode()],
            'opening_balance' => (string) $statement['opening'],
            'lines' => array_map(static fn (array $line): array => [
                'date' => $line['date']->format('Y-m-d'),
                'kind' => $line['kind'],
                'reference' => $line['reference'],
                'id' => $line['id'],
                'debit' => (string) $line['debit'],
                'credit' => (string) $line['credit'],
                'balance' => (string) $line['balance'],
            ], $statement['lines']),
            'total_debit' => (string) $statement['debit'],
            'total_credit' => (string) $statement['credit'],
            'closing_balance' => (string) $statement['closing'],
        ];
    }

    /**
     * What was invoiced, credited and received from each client over a year.
     *
     * @param int|null $year Calendar year (defaults to the current one)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_revenue_by_client', description: 'Revenue by client over a calendar year: invoiced, credited, net and received, by currency. Needs mcp:finance.')]
    #[McpScopeRequired(McpScope::Finance)]
    public function getRevenueByClient(?int $year = null): array
    {
        $this->scopeGuard->require(McpScope::Finance);

        $year ??= (int) $this->clock->now()->format('Y');
        $currencies = [];

        foreach ($this->revenueByClient->forYear($year) as $currency => $table) {
            $currencies[$currency] = [
                'clients' => array_values(array_map(static fn (array $row): array => [
                    'client_id' => $row['clientId'],
                    'client' => $row['client'],
                    'invoices' => $row['invoices'],
                    'invoiced' => (string) $row['invoiced'],
                    'credited' => (string) $row['credited'],
                    'net' => (string) $row['net'],
                    'received' => (string) $row['received'],
                ], $table['rows'])),
                'invoiced' => (string) $table['invoiced'],
                'credited' => (string) $table['credited'],
                'net' => (string) $table['net'],
                'received' => (string) $table['received'],
            ];
        }

        return ['year' => $year, 'by_currency' => $currencies];
    }

    /**
     * The invoices and credit notes handed to the electronic invoicing
     * platform, newest first, with what the platform answered.
     *
     * @param bool $failed_only Only those the platform did not take
     * @param int  $limit       Max rows (1..100)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_einvoice_submissions', description: 'Invoices and credit notes sent on the electronic invoicing platform, newest first, with the platform\'s answer; failed_only keeps those not taken.')]
    #[McpScopeRequired(McpScope::Read)]
    public function listEinvoiceSubmissions(bool $failed_only = false, int $limit = 25): array
    {
        $this->scopeGuard->require(McpScope::Read);

        $qb = $this->submissionRepository->createQueryBuilder('s')
            ->orderBy('s.created', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)));

        if ($failed_only) {
            $qb->andWhere('s.success = false');
        }

        $results = array_map(static fn (ElectronicInvoiceSubmission $submission): array => [
            'id' => $submission->getId()?->toRfc4122(),
            'document_number' => $submission->getDocumentNumber(),
            'invoice_id' => $submission->getInvoice()?->getId()?->toRfc4122(),
            'credit_note_id' => $submission->getCreditNote()?->getId()?->toRfc4122(),
            'success' => $submission->isSuccess(),
            'status_code' => $submission->getStatusCode(),
            'message' => $submission->getMessage(),
            'sent_at' => $submission->getCreated()?->format(DateTimeInterface::ATOM),
        ], $qb->getQuery()->getResult());

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * @return list<string>
     */
    private function bucketLabels(): array
    {
        $labels = ['not_due'];
        $previous = 0;

        foreach (\array_slice(AgedReceivables::BUCKETS, 1) as $bound) {
            $labels[] = sprintf('%d_%d_days', $previous + 1, $bound);
            $previous = $bound;
        }

        $labels[] = sprintf('over_%d_days', $previous);

        return $labels;
    }

    /**
     * @param list<string>     $labels
     * @param list<BigInteger> $amounts
     *
     * @return array<string, string>
     */
    private function buckets(array $labels, array $amounts): array
    {
        $result = [];

        foreach ($labels as $index => $label) {
            $result[$label] = (string) ($amounts[$index] ?? BigInteger::zero());
        }

        return $result;
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value)->setTime(0, 0);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid date "%s". Use ISO-8601 format (YYYY-MM-DD).', $value));
        }
    }
}
