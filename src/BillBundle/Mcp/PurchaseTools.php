<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\BillBundle\Mcp;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillPaymentMethod;
use Augias\BillBundle\Manager\BillManager;
use Augias\BillBundle\Manager\BillPaymentManager;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManagerInterface;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The buying side: purchases (supplier invoices) entered by hand or taken from
 * an invoice received on the electronic invoicing platform, their payments,
 * and the answer given to the supplier.
 */
final readonly class PurchaseTools
{
    public function __construct(
        private BillRepository $billRepository,
        private ClientRepository $clientRepository,
        private CategoryRepository $categoryRepository,
        private ElectronicInvoiceReceiptRepository $receiptRepository,
        private ElectronicInvoiceReceiptManagerInterface $receiptManager,
        private BillManager $billManager,
        private BillPaymentManager $billPaymentManager,
        private SystemConfig $systemConfig,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
        #[Autowire(service: 'state_machine.bill')]
        private WorkflowInterface $billWorkflow,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Enter a purchase (supplier invoice) as a draft. Confirm it with
     * apply_bill_transition "confirm" once checked.
     *
     * @param int|string  $total_amount           Total including tax, in the minor unit (e.g. cents)
     * @param string|null $supplier_id            Supplier ULID (a client record marked as supplier)
     * @param string|null $supplier_name          Name of a new supplier, when supplier_id is not given
     * @param int|string|null $tax_amount         VAT included in the total, in the minor unit; refused for a company in VAT franchise
     * @param string|null $bill_number            The supplier's invoice number
     * @param string|null $issue_date             ISO-8601 date of the supplier's invoice
     * @param string|null $due_date               ISO-8601 due date
     * @param string|null $currency               ISO 4217 code; defaults to the supplier's currency, then EUR
     * @param string      $supply_type            "services" (default) or "goods": decides when the VAT is deductible
     * @param bool        $supplier_vat_on_debits The supplier opted for VAT on debits (services only)
     * @param string|null $category_id            Purchase category ULID
     * @param string|null $notes                  Notes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_bill', description: 'Enter a purchase (supplier invoice) as a draft: supplier, total incl. tax and VAT in the minor unit, dates, supplier invoice number. Confirm it with apply_bill_transition.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createBill(
        int | string $total_amount,
        ?string $supplier_id = null,
        ?string $supplier_name = null,
        int | string | null $tax_amount = null,
        ?string $bill_number = null,
        ?string $issue_date = null,
        ?string $due_date = null,
        ?string $currency = null,
        string $supply_type = 'services',
        bool $supplier_vat_on_debits = false,
        ?string $category_id = null,
        ?string $notes = null,
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $supplier = $this->supplier($supplier_id, $supplier_name);

        $bill = new Bill();
        $bill->setSupplier($supplier);
        $bill->setCurrencyCode(strtoupper($currency ?? $supplier->getCurrencyCode() ?? 'EUR'));
        $bill->setTotalAmount($this->amount($total_amount, 'total_amount') ?? throw new ToolCallException('total_amount is required.'));
        $bill->setBillNumber($bill_number);
        $bill->setIssueDate($issue_date !== null ? $this->date($issue_date) : CarbonImmutable::today());
        $bill->setDueDate($due_date !== null ? $this->date($due_date) : null);
        $bill->setNotes($notes);
        $bill->setCategory($this->category($category_id));

        if ($this->systemConfig->isVatExempt()) {
            if ($tax_amount !== null && ! $this->amount($tax_amount, 'tax_amount')?->isZero()) {
                throw new ToolCallException('The company is in VAT franchise (franchise en base): it deducts no VAT, so a purchase takes no tax_amount.');
            }
        } else {
            $bill->setTaxAmount($this->amount($tax_amount, 'tax_amount'));
            $bill->setSupplyType(SupplyType::tryFrom($supply_type) ?? throw new ToolCallException('supply_type must be "services" or "goods".'));
            $bill->setSupplierVatOnDebits($supplier_vat_on_debits);
        }

        $errors = $this->validator->validate($bill);

        if (\count($errors) > 0) {
            $messages = [];

            foreach ($errors as $error) {
                $messages[] = sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage());
            }

            throw new ToolCallException(sprintf('Validation failed: %s', implode('; ', $messages)));
        }

        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $this->normalizer->normalize($bill);
    }

    /**
     * Move a purchase through its workflow.
     *
     * @param string $bill_id    Purchase ULID
     * @param string $transition One of: confirm, cancel, overdue, pay, reopen, archive, edit
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'apply_bill_transition', description: 'Apply a workflow transition to a purchase: confirm (draft to pending), cancel, reopen, archive, edit (back to draft).')]
    #[McpScopeRequired(McpScope::Write)]
    public function applyBillTransition(string $bill_id, string $transition): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $bill = $this->bill($bill_id);

        if (! $this->billWorkflow->can($bill, $transition)) {
            throw new ToolCallException(sprintf('Transition "%s" is not enabled for a purchase in status "%s".', $transition, $bill->getStatus()->value));
        }

        $this->billWorkflow->apply($bill, $transition);
        $this->entityManager->flush();

        return $this->normalizer->normalize($bill);
    }

    /**
     * Record money paid to the supplier against a purchase. The purchase is
     * marked paid once nothing is left on it.
     *
     * @param string      $bill_id   Purchase ULID
     * @param int|string  $amount    Amount paid, in the minor unit, at most what is left to pay
     * @param string|null $paid_date ISO-8601 date (defaults to today)
     * @param string      $method    One of: bank_transfer, check, credit_card, cash, other
     * @param string|null $reference Transfer or cheque reference
     * @param string|null $notes     Notes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'record_bill_payment', description: 'Record a payment made to a supplier against a purchase (amount in the minor unit); the purchase is marked paid once settled.')]
    #[McpScopeRequired(McpScope::Write)]
    public function recordBillPayment(
        string $bill_id,
        int | string $amount,
        ?string $paid_date = null,
        string $method = 'bank_transfer',
        ?string $reference = null,
        ?string $notes = null,
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $bill = $this->bill($bill_id);
        $paid = $this->amount($amount, 'amount') ?? throw new ToolCallException('amount is required.');
        $left = BigInteger::of($bill->getBalance()->getAmount());

        if (! $paid->isPositive()) {
            throw new ToolCallException('The amount must be above zero.');
        }

        if ($paid->isGreaterThan($left)) {
            throw new ToolCallException(sprintf('The amount is more than what is left to pay on this purchase (%s, in the minor unit).', (string) $left));
        }

        $this->billPaymentManager->recordPayment(
            $bill,
            $paid,
            $paid_date !== null ? $this->date($paid_date) : CarbonImmutable::today(),
            BillPaymentMethod::tryFrom($method) ?? throw new ToolCallException(sprintf(
                'Invalid method "%s". Valid values: %s.',
                $method,
                implode(', ', array_map(static fn (BillPaymentMethod $case): string => $case->value, BillPaymentMethod::cases())),
            )),
            $reference,
            $notes,
        );

        return $this->normalizer->normalize($bill);
    }

    /**
     * List the invoices received on the electronic invoicing platform, with
     * the purchase each became, if any, and the answer given to the supplier.
     *
     * @param bool $unanswered_only Only those not accepted or refused yet
     * @param int  $limit           Max rows (1..100)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_received_invoices', description: 'List invoices received from suppliers on the electronic invoicing platform, with the answer given and the purchase each became.')]
    #[McpScopeRequired(McpScope::Read)]
    public function listReceivedInvoices(bool $unanswered_only = false, int $limit = 25): array
    {
        $this->scopeGuard->require(McpScope::Read);
        $limit = max(1, min($limit, 100));

        $receipts = $this->receiptRepository->createQueryBuilder('r')
            ->orderBy('r.issueDate', 'DESC')
            ->setMaxResults($unanswered_only ? 500 : $limit)
            ->getQuery()
            ->getResult();

        $results = [];

        foreach ($receipts as $receipt) {
            if ($unanswered_only && true === $receipt->getResponse()?->isFinal()) {
                continue;
            }

            $results[] = $this->receipt($receipt);

            if (\count($results) >= $limit) {
                break;
            }
        }

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * Turn an invoice received on the platform into a purchase; the one
     * already made from it is returned if there is one.
     *
     * @param string $received_invoice_id Received invoice ULID (from list_received_invoices)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_bill_from_received_invoice', description: 'Turn an invoice received on the electronic invoicing platform into a purchase (or return the purchase already made from it).')]
    #[McpScopeRequired(McpScope::Write)]
    public function createBillFromReceivedInvoice(string $received_invoice_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $receipt = $this->findReceipt($received_invoice_id);
        $bill = $this->billRepository->findOneBy(['electronicInvoiceReceipt' => $receipt]);

        if (! $bill instanceof Bill) {
            $bill = $this->billManager->createFromReceipt($receipt);
        }

        return $this->normalizer->normalize($bill);
    }

    /**
     * Answer a supplier on the platform about an invoice received: accept it,
     * dispute it (it stays open), or refuse it. A dispute or a refusal needs a
     * reason code.
     *
     * @param string      $received_invoice_id Received invoice ULID
     * @param string      $response            "accepted", "disputed" or "refused"
     * @param string|null $reason              Reason code for a dispute or a refusal (e.g. MONTANTTOTAL_ERR, DOUBLON, TX_TVA_ERR, AUTRE)
     * @param string|null $comment             Free text sent to the supplier
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'respond_to_received_invoice', description: 'Answer a supplier about a received electronic invoice: accepted, disputed or refused (the last two need a reason code such as MONTANTTOTAL_ERR, DOUBLON, TX_TVA_ERR or AUTRE).')]
    #[McpScopeRequired(McpScope::Write)]
    public function respondToReceivedInvoice(string $received_invoice_id, string $response, ?string $reason = null, ?string $comment = null): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $receipt = $this->findReceipt($received_invoice_id);

        $answer = match ($response) {
            'accepted' => ReceiptResponse::Accepted,
            'disputed' => ReceiptResponse::Disputed,
            'refused' => ReceiptResponse::Refused,
            default => throw new ToolCallException('response must be "accepted", "disputed" or "refused".'),
        };

        $reasonCode = null;

        if ($reason !== null) {
            $reasonCode = ResponseReason::tryFrom($reason) ?? throw new ToolCallException(sprintf(
                'Unknown reason "%s". Valid codes: %s.',
                $reason,
                implode(', ', array_map(static fn (ResponseReason $case): string => $case->value, ResponseReason::cases())),
            ));
        }

        if (! $this->receiptManager->canRespond($receipt)) {
            throw new ToolCallException('This invoice cannot be answered: it has a final answer already, or no platform account can send one.');
        }

        try {
            $this->receiptManager->respond($receipt, $answer, $reasonCode, $comment);
        } catch (RuntimeException $exception) {
            throw new ToolCallException($this->translator->trans($exception->getMessage(), [], null, 'en'), 0, $exception);
        }

        return $this->receipt($receipt);
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(ElectronicInvoiceReceipt $receipt): array
    {
        $bill = $this->billRepository->findOneBy(['electronicInvoiceReceipt' => $receipt]);

        return [
            'id' => $receipt->getId()?->toRfc4122(),
            'invoice_number' => $receipt->getInvoiceNumber(),
            'seller_name' => $receipt->getSellerName(),
            'seller_identifier' => $receipt->getSellerIdentifier(),
            'issue_date' => $receipt->getIssueDate()?->format('Y-m-d'),
            'currency' => $receipt->getCurrencyCode(),
            'total_amount' => $receipt->getTotalAmount()?->__toString(),
            'tax_amount' => $receipt->getTaxAmount()?->__toString(),
            'supply_type' => $receipt->getSupplyType()?->value,
            'response' => match ($receipt->getResponse()) {
                ReceiptResponse::Accepted => 'accepted',
                ReceiptResponse::Disputed => 'disputed',
                ReceiptResponse::Refused => 'refused',
                null => null,
            },
            'response_reason' => $receipt->getResponseReason()?->value,
            'responded_at' => $receipt->getRespondedAt()?->format(DateTimeImmutable::ATOM),
            'bill_id' => $bill?->getId()?->toRfc4122(),
        ];
    }

    private function supplier(?string $supplierId, ?string $supplierName): Client
    {
        if ($supplierId !== null) {
            $supplier = $this->clientRepository->find(UlidParser::parse($supplierId, 'supplier_id'));

            if (! $supplier instanceof Client) {
                throw new ToolCallException(sprintf('Supplier %s not found.', $supplierId));
            }

            if (! $supplier->isSupplier()) {
                // As on the form, where any client can be picked: a client who
                // also sells to the company becomes a supplier too.
                $supplier->setIsSupplier(true);
            }

            return $supplier;
        }

        if ($supplierName === null || trim($supplierName) === '') {
            throw new ToolCallException('Give a supplier_id, or the supplier_name of a new supplier.');
        }

        $supplier = new Client();
        $supplier->setName(trim($supplierName))
            ->setIsClient(false)
            ->setIsSupplier(true);
        $this->entityManager->persist($supplier);

        return $supplier;
    }

    private function category(?string $categoryId): ?Category
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $category = $this->categoryRepository->find(UlidParser::parse($categoryId, 'category_id'));

        if (! $category instanceof Category || ! \in_array($category, $this->categoryRepository->sortedForUsage(CategoryUsage::Purchase), true)) {
            throw new ToolCallException(sprintf('Purchase category %s not found.', $categoryId));
        }

        return $category;
    }

    private function bill(string $billId): Bill
    {
        $bill = $this->billRepository->find(UlidParser::parse($billId, 'bill_id'));

        if (! $bill instanceof Bill) {
            throw new ToolCallException(sprintf('Purchase %s not found.', $billId));
        }

        return $bill;
    }

    private function findReceipt(string $receiptId): ElectronicInvoiceReceipt
    {
        $receipt = $this->receiptRepository->find(UlidParser::parse($receiptId, 'received_invoice_id'));

        if (! $receipt instanceof ElectronicInvoiceReceipt) {
            throw new ToolCallException(sprintf('Received invoice %s not found.', $receiptId));
        }

        return $receipt;
    }

    private function amount(int | string | null $value, string $field): ?BigInteger
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return BigInteger::of($value);
        } catch (MathException) {
            throw new ToolCallException(sprintf('%s must be a whole number in the minor unit (e.g. cents).', $field));
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new ToolCallException(sprintf('Invalid date "%s". Use ISO-8601 format (YYYY-MM-DD).', $value));
        }
    }
}
