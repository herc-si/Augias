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

namespace Augias\InvoiceBundle\Tests\Listener\Mailer;

use Augias\InvoiceBundle\Email\InvoiceReminderEmail;
use Augias\InvoiceBundle\Email\ManualInvoiceReminderEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\ReminderType;
use Augias\InvoiceBundle\Listener\Mailer\ReminderSubjectListener;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ReminderSubjectListener::class)]
final class ReminderSubjectListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testListenerSetsPreDueSubject(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-001');

        $email = new InvoiceReminderEmail($invoice, ReminderType::PreDue, 3);

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);

        self::assertSame('Upcoming Payment Due: Invoice INV-001', $email->getSubject());
    }

    public function testListenerSetsOverdue1Subject(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-002');

        $email = new InvoiceReminderEmail($invoice, ReminderType::Overdue1);

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);

        self::assertSame('Payment Reminder: Invoice INV-002', $email->getSubject());
    }

    public function testListenerSetsOverdue7Subject(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-003');

        $email = new InvoiceReminderEmail($invoice, ReminderType::Overdue7);

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);

        self::assertSame('Payment Overdue: Invoice INV-003', $email->getSubject());
    }

    public function testListenerSetsOverdue14Subject(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-004');

        $email = new InvoiceReminderEmail($invoice, ReminderType::Overdue14);

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);

        self::assertSame('URGENT: Invoice INV-004 - Immediate Action Required', $email->getSubject());
    }

    public function testListenerIgnoresNonReminderEmails(): void
    {
        $email = M::mock(Email::class);
        $email->shouldNotReceive('setSubject');

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);
    }

    public function testListenerSkipsWhenSubjectAlreadySet(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-005');

        $email = new InvoiceReminderEmail($invoice, ReminderType::PreDue);
        $email->subject('Custom Subject');

        $listener = new ReminderSubjectListener($this->translator());

        $event = new MessageEvent($email, M::mock(Envelope::class), 'smtp');

        $listener($event);

        // Should not modify existing subject
        self::assertSame('Custom Subject', $email->getSubject());
    }

    public function testAManualReminderGetsItsSubjectToo(): void
    {
        $invoice = new Invoice();
        $invoice->setInvoiceId('INV-005');

        $email = new ManualInvoiceReminderEmail($invoice);

        (new ReminderSubjectListener($this->translator('fr')))(new MessageEvent($email, M::mock(Envelope::class), 'smtp'));

        self::assertSame('Rappel de paiement : facture INV-005', $email->getSubject());
    }

    private function translator(string $locale = 'en'): TranslatorInterface
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/email.' . $locale . '.yml', $locale, 'email');

        return $translator;
    }
}
