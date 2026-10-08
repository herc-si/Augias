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

namespace Augias\InvoiceBundle\Email;

use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Activity\DocumentEmail;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Journal\Journalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * @see \Augias\InvoiceBundle\Tests\Email\ManualInvoiceReminderEmailTest
 */
final class ManualInvoiceReminderEmail extends TemplatedEmail implements DocumentEmail
{
    public function __construct(
        private readonly Invoice $invoice
    ) {
        parent::__construct();

        $this->subject('Payment Reminder: Invoice ' . $invoice->getInvoiceId());
        $this->htmlTemplate('@AugiasInvoice/Email/manual_reminder.html.twig');
        $this->textTemplate('@AugiasInvoice/Email/manual_reminder.text.twig');
        $this->context(['invoice' => $this->invoice]);
        $this->to(...$this->invoice->getUsers()->map(fn (Contact $user) => Address::create(sprintf('%s %s <%s>', $user->getFirstName(), $user->getLastName(), $user->getEmail())))->toArray());
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function activityDocument(): Journalled
    {
        return $this->getInvoice();
    }

    public function activityCompany(): Company
    {
        return $this->getInvoice()->getCompany();
    }

    public function activityDetail(): string
    {
        return 'reminder';
    }
}
