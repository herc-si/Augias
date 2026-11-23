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

namespace Augias\SaasBundle\Payment\Stripe;

use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Augias\PaymentBundle\Entity\Payment;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_filter;
use function array_values;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function max;

/**
 * Issues the credit note (avoir) for a subscription payment Stripe gave back.
 *
 * HERC SI's invoice for a payment stays issued and paid; the money going back
 * is a credit note against it (Rémi, 23/11/2026), numbered in HERC SI's
 * sequence, emailed to the subscriber and sent electronically like the
 * invoice was. It is then recorded as refunded, on the day Stripe refunded
 * it: the books see the money leave once, through the credit note — the
 * payment itself is left captured, since marking it refunded as well would
 * count that money out twice.
 *
 * What Stripe says has been refunded on the charge, in total, is compared
 * with what the invoice's issued credit notes already give back: a refund
 * reported twice, or one HERC SI credited by hand, issues nothing more. All
 * of it refunded cancels the invoice line for line; part of it credits that
 * part.
 *
 * Stripe may report the refund before the payment: the invoice does not exist
 * yet, and the issuer calls this again once it has issued it.
 *
 * @see \Augias\SaasBundle\Tests\Payment\Stripe\SubscriptionRefundCreditorTest
 */
final readonly class SubscriptionRefundCreditor
{
    public function __construct(
        private StripeApi $api,
        private CompanySelector $companySelector,
        private EntityManagerInterface $entityManager,
        private CreditNoteRepository $creditNotes,
        private CreditNoteFormManager $formManager,
        private TotalCalculator $totalCalculator,
        #[Autowire(service: 'state_machine.credit_note')]
        private WorkflowInterface $creditNoteStateMachine,
        private CreditNoteAllocator $allocator,
        private MailerInterface $mailer,
        private SystemConfig $systemConfig,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire(env: 'AUGIAS_SAAS_BILLING_COMPANY')]
        private string $billingCompany,
    ) {
    }

    /**
     * For a `charge.refunded` event: reads the charge, with its refunds.
     */
    public function refunded(string $chargeId): ?CreditNote
    {
        return $this->credit($this->api->get('charges/' . $chargeId, ['expand' => ['refunds']]));
    }

    /**
     * @param array<string, mixed> $charge a Stripe charge, its refunds expanded or not
     */
    public function credit(array $charge): ?CreditNote
    {
        $refunded = $charge['amount_refunded'] ?? 0;
        $invoiceId = $charge['invoice'] ?? null;
        $invoiceId = is_array($invoiceId) ? ($invoiceId['id'] ?? null) : $invoiceId;

        // Not a subscription's payment, or nothing given back.
        if ($this->billingCompany === '' || ! is_int($refunded) || $refunded <= 0 || ! is_string($invoiceId)) {
            return null;
        }

        $this->companySelector->switchCompany(Ulid::fromString($this->billingCompany));

        try {
            $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['reference' => $invoiceId]);
            $invoice = $payment instanceof Payment ? $payment->getInvoice() : null;

            if (! $invoice instanceof Invoice) {
                // The payment has not been invoiced yet: the issuer credits it
                // once it has.
                return null;
            }

            $toCredit = BigInteger::of($refunded)->minus($this->creditNotes->issuedTotalFor($invoice)->toBigInteger());

            if (! $toCredit->isPositive()) {
                return null;
            }

            $refundedOn = $this->refundedOn($charge);
            $creditNote = $this->creditNote($invoice, $toCredit, $refundedOn);

            $this->allocator->allocate(
                $creditNote,
                AllocationKind::Refund,
                $creditNote->getTotal()->toBigInteger(),
                null,
                $refundedOn,
                implode(', ', $this->refundIds($charge)) ?: (string) ($charge['id'] ?? ''),
            );

            $this->deliver($creditNote);

            return $creditNote;
        } finally {
            $this->companySelector->reset();
        }
    }

    private function creditNote(Invoice $invoice, BigInteger $amount, DateTimeImmutable $refundedOn): CreditNote
    {
        $locale = (string) ($this->systemConfig->get('system/company/locale') ?? 'fr');

        if ($amount->isEqualTo($invoice->getTotal()->toBigInteger())) {
            $dto = $this->formManager->cancellationOf($invoice);
        } else {
            $dto = $this->formManager->blank();
            $dto->client = $invoice->getClient();
            $dto->creditedInvoice = $invoice;
            $dto->reason = CreditReason::CommercialGesture;
            $dto->subject = $invoice->getSubject();
            $dto->lines->add(new CreditNoteLine()
                ->setDescription($this->translator->trans('saas.billing.credit_note_line.partial_refund', [
                    '%line%' => (string) $invoice->getLines()->first()?->getDescription(),
                ], 'messages', $locale))
                ->setQty(1)
                ->setPrice($amount->toInt())
                ->updateTotal());

            foreach ($invoice->getUsers() as $contact) {
                $dto->users->add($contact);
            }
        }

        $dto->notes = $this->translator->trans('saas.billing.credit_note_notes', ['%date%' => $refundedOn->format('d/m/Y')], 'messages', $locale);

        $creditNote = $this->formManager->createFromDTO($dto);
        $this->totalCalculator->calculateTotals($creditNote);
        $this->entityManager->persist($creditNote);
        $this->entityManager->flush();

        // Numbered, put on the client's credit and sent electronically by the
        // issue listeners, as one issued by hand.
        $this->creditNoteStateMachine->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
        $creditNote->setIssuedAt(CarbonImmutable::now());
        $this->entityManager->flush();

        return $creditNote;
    }

    /**
     * The latest refund that went through: the day the money left.
     *
     * @param array<string, mixed> $charge
     */
    private function refundedOn(array $charge): DateTimeImmutable
    {
        $latest = null;

        foreach ($this->refunds($charge) as $refund) {
            if (($refund['status'] ?? 'succeeded') === 'succeeded' && is_int($refund['created'] ?? null)) {
                $latest = max($latest ?? 0, $refund['created']);
            }
        }

        return $latest !== null ? StripeIntegration::fromTimestamp($latest) : new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return list<string>
     */
    private function refundIds(array $charge): array
    {
        $ids = [];
        foreach ($this->refunds($charge) as $refund) {
            if (is_string($refund['id'] ?? null)) {
                $ids[] = $refund['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return list<array<string, mixed>>
     */
    private function refunds(array $charge): array
    {
        $refunds = $charge['refunds'] ?? null;
        $data = is_array($refunds) ? ($refunds['data'] ?? []) : [];

        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }

    private function deliver(CreditNote $creditNote): void
    {
        if ($creditNote->getUsers()->isEmpty()) {
            return;
        }

        try {
            $this->mailer->send(new CreditNoteEmail($creditNote));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not email a subscription refund credit note.', ['exception' => $e, 'credit_note' => $creditNote->getCreditNoteId()]);
        }
    }
}
