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

namespace Augias\InvoiceBundle\Tests\Twig\Extension;

use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Storage\StoredDocument;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Twig\Extension\InvoiceTemplateExtension;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

#[CoversClass(InvoiceTemplateExtension::class)]
final class InvoiceTemplateExtensionTest extends TestCase
{
    private InvoiceTemplateExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new InvoiceTemplateExtension(new IdentityTranslator());
    }

    public function testTheMentionPromisesDocumentsOnRequestUntilEveryDisbursementHasOne(): void
    {
        $invoice = new Invoice();
        $first = new Line()->setDisbursement(true);
        $second = new Line()->setDisbursement(true);
        $invoice->addLine(new Line());
        $invoice->addLine($first);
        $invoice->addLine($second);

        self::assertSame('invoice.disbursement.mention', $this->extension->disbursementMention($invoice));

        $first->addReceipt($this->receipt());

        self::assertSame('invoice.disbursement.mention', $this->extension->disbursementMention($invoice), 'One of two is not all.');

        $second->addReceipt($this->receipt());

        self::assertSame('invoice.disbursement.mention_attached', $this->extension->disbursementMention($invoice));
    }

    /**
     * Previews render the templates with something that is not an invoice;
     * the mention must still come out rather than fail.
     */
    public function testTheMentionFallsBackForAnythingButAnInvoice(): void
    {
        self::assertSame('invoice.disbursement.mention', $this->extension->disbursementMention(new \stdClass()));
    }

    public function testHasOutstandingBalanceIsFalseForFullyPaidInvoice(): void
    {
        $invoice = new Invoice()->setBalance(BigInteger::zero());
        $invoice->addPayment($this->capturedPayment());

        self::assertFalse($this->extension->hasOutstandingBalance($invoice));
    }

    public function testHasOutstandingBalanceIsFalseForOverpaidInvoice(): void
    {
        // Negative balance can occur when a payment exceeds the invoice total —
        // it is a credit, not money owed by the client.
        $invoice = new Invoice()->setBalance(BigInteger::of(-50000));
        $invoice->addPayment($this->capturedPayment());

        self::assertFalse($this->extension->hasOutstandingBalance($invoice));
    }

    public function testHasOutstandingBalanceIsFalseForUnpaidInvoiceWithNoCapturedPayments(): void
    {
        // Brand-new invoice with no payments yet — the full balance is owed
        // but there is no captured payment to reconcile against.
        $invoice = new Invoice()->setBalance(BigInteger::of(150000));

        self::assertFalse($this->extension->hasOutstandingBalance($invoice));
    }

    public function testHasOutstandingBalanceIsFalseWhenOnlyPendingPayments(): void
    {
        // Pending/failed payments must not flag a "balance due" — only
        // captured payments count toward partial-payment reconciliation.
        $invoice = new Invoice()->setBalance(BigInteger::of(150000));
        $invoice->addPayment($this->payment(PaymentStatus::New));

        self::assertFalse($this->extension->hasOutstandingBalance($invoice));
    }

    public function testHasOutstandingBalanceIsTrueForPartiallyPaidInvoice(): void
    {
        $invoice = new Invoice()->setBalance(BigInteger::of(50000));
        $invoice->addPayment($this->capturedPayment());

        self::assertTrue($this->extension->hasOutstandingBalance($invoice));
    }

    public function testCapturedPaymentsFiltersByStatus(): void
    {
        $captured = $this->capturedPayment();
        $pending = $this->payment(PaymentStatus::New);
        $failed = $this->payment(PaymentStatus::Failed);

        $invoice = new Invoice();
        $invoice->addPayment($pending);
        $invoice->addPayment($captured);
        $invoice->addPayment($failed);

        $result = $this->extension->capturedPayments($invoice);

        self::assertCount(1, $result);
        self::assertSame($captured, $result[0]);
    }

    public function testCapturedPaymentsReturnsEmptyArrayWhenNoneCaptured(): void
    {
        $invoice = new Invoice();
        $invoice->addPayment($this->payment(PaymentStatus::New));

        self::assertSame([], $this->extension->capturedPayments($invoice));
    }

    public function testPrimaryContactReturnsFirstUser(): void
    {
        $jane = new Contact()->setFirstName('Jane');
        $john = new Contact()->setFirstName('John');

        $invoice = new Invoice();
        $invoice->addUser($jane);
        $invoice->addUser($john);

        self::assertSame($jane, $this->extension->primaryContact($invoice));
    }

    public function testPrimaryContactReturnsNullWhenNoUsers(): void
    {
        self::assertNull($this->extension->primaryContact(new Invoice()));
    }

    private function capturedPayment(): Payment
    {
        return $this->payment(PaymentStatus::Captured);
    }

    private function payment(PaymentStatus $status): Payment
    {
        return new Payment()->setStatus($status);
    }

    private function receipt(): DisbursementReceipt
    {
        return DisbursementReceipt::of(new StoredDocument('receipt.pdf', 'application/pdf', 1, 'checksum', 'path/receipt.pdf'));
    }
}
