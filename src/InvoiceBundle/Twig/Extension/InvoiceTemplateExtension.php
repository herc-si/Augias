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

namespace Augias\InvoiceBundle\Twig\Extension;

use Augias\ClientBundle\Entity\Contact;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\MoneyBundle\Formatter\MoneyFormatterInterface;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Money\Currency;
use Money\Money;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;
use function array_filter;
use function array_values;

/**
 * @see \Augias\InvoiceBundle\Tests\Twig\Extension\InvoiceTemplateExtensionTest
 */
final readonly class InvoiceTemplateExtension
{
    public function __construct(
        private TranslatorInterface $translator,
        private MoneyFormatterInterface $moneyFormatter,
    ) {
    }

    /**
     * What the invoice says about the disbursements it no longer carries:
     * where they went, and how much. The note is a document of its own, and
     * an invoice whose total is smaller than what the client is asked to pay
     * has to say why.
     *
     * @throws MathException
     */
    #[AsTwigFunction(name: 'disbursement_note_reference')]
    public function disbursementNoteReference(object $document): string
    {
        if (! $document instanceof Invoice || ! $document->hasDisbursements()) {
            return '';
        }

        $amount = $document->getDisbursementTotal()->toBigDecimal()->toScale(0, RoundingMode::HalfEven);
        $currency = $document->getClient()?->getCurrency();

        return $this->translator->trans('invoice.disbursement.note.reference', [
            '%note%' => $document->getDisbursementNoteId(),
            '%amount%' => $currency instanceof Currency ? $this->moneyFormatter->format(new Money((string) $amount, $currency)) : (string) $amount,
        ]);
    }

    /**
     * The ground a disbursement rests on, as the document has to print it.
     *
     * The supporting documents are said to be attached once every
     * disbursement on the invoice has one — they then go out with the
     * e-mail and are offered on the client's copy. Until then, and for any
     * document that cannot carry them, they are available on request.
     */
    #[AsTwigFunction(name: 'disbursement_mention')]
    public function disbursementMention(object $document): string
    {
        return $this->translator->trans(
            $document instanceof Invoice && $document->hasAllDisbursementReceipts()
                ? 'invoice.disbursement.mention_attached'
                : 'invoice.disbursement.mention',
        );
    }

    #[AsTwigFunction(name: 'invoice_has_outstanding_balance')]
    public function hasOutstandingBalance(Invoice $invoice): bool
    {
        if (! $invoice->getBalance()->isPositive()) {
            return false;
        }

        return $this->capturedPayments($invoice) !== [];
    }

    /**
     * @return list<Payment>
     */
    #[AsTwigFunction(name: 'invoice_captured_payments')]
    public function capturedPayments(Invoice $invoice): array
    {
        return array_values(array_filter(
            $invoice->getPayments()->toArray(),
            static fn (Payment $payment): bool => $payment->getStatus() === PaymentStatus::Captured,
        ));
    }

    #[AsTwigFunction(name: 'invoice_primary_contact')]
    public function primaryContact(Invoice $invoice): ?Contact
    {
        $first = $invoice->getUsers()->first();

        return $first instanceof Contact ? $first : null;
    }
}
