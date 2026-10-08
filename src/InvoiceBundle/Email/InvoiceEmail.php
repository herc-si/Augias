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
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class InvoiceEmail extends TemplatedEmail implements DocumentEmail
{
    public function __construct(
        private readonly Invoice $invoice
    ) {
        parent::__construct();

        $this->htmlTemplate('@AugiasInvoice/Email/invoice.html.twig');
        $this->context(['invoice' => $this->invoice]);
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

    public function activityDetail(): ?string
    {
        return null;
    }
}
