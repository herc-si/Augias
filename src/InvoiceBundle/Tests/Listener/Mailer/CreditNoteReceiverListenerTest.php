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

namespace Augias\InvoiceBundle\Tests\Listener\Mailer;

use Augias\ClientBundle\Entity\Contact;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Listener\Mailer\CreditNoteReceiverListener;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

final class CreditNoteReceiverListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAddressesTheCreditNoteContacts(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldReceive('get')
            ->with('invoice/bcc_address')
            ->andReturn('bcc@example.com');

        $creditNote = new CreditNote();
        $creditNote->addUser(new Contact()->setEmail('test@example.com')->setFirstName('Test')->setLastName('User'));
        $creditNote->addUser(new Contact()->setEmail('another@example.com')->setFirstName('Another'));

        $message = new CreditNoteEmail($creditNote);
        new CreditNoteReceiverListener($config)(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertEquals([new Address('test@example.com', 'Test User'), new Address('another@example.com', 'Another')], $message->getTo());
        self::assertEquals([new Address('bcc@example.com')], $message->getBcc());
    }

    public function testKeepsRecipientsAlreadySet(): void
    {
        $config = M::mock(SystemConfig::class);
        $config->shouldNotReceive('get');

        $creditNote = new CreditNote();
        $creditNote->addUser(new Contact()->setEmail('test@example.com'));

        $message = new CreditNoteEmail($creditNote);
        $message->to('chosen@example.com');
        new CreditNoteReceiverListener($config)(new MessageEvent($message, Envelope::create($message), 'smtp'));

        self::assertEquals([new Address('chosen@example.com')], $message->getTo());
    }

    public function testEvents(): void
    {
        self::assertSame([MessageEvent::class], \array_keys(CreditNoteReceiverListener::getSubscribedEvents()));
    }
}
