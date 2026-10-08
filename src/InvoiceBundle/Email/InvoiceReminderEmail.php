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

use Augias\CoreBundle\Activity\DocumentEmail;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Journal\Journalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\ReminderType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class InvoiceReminderEmail extends TemplatedEmail implements DocumentEmail
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ReminderType $reminderType,
        private readonly ?int $daysUntilDue = null,
    ) {
        parent::__construct();

        $this->htmlTemplate('@AugiasInvoice/Email/reminder.html.twig');
        $this->textTemplate('@AugiasInvoice/Email/reminder.text.twig');
        $this->context([
            'invoice' => $this->invoice,
            'reminder_type' => $this->reminderType->value,
            'days_until_due' => $this->daysUntilDue,
        ]);
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getReminderType(): ReminderType
    {
        return $this->reminderType;
    }

    public function getDaysUntilDue(): ?int
    {
        return $this->daysUntilDue;
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
