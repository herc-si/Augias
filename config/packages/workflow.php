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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Model\Graph as BillGraph;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Enum\RecurringInvoiceStatus;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Model\Graph as InvoiceGraph;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Model\Graph as QuoteGraph;

return App::config([
    'framework' => [
        'workflows' => [
            'enabled' => true,
            'workflows' => [
                'invoice' => [
                    'type' => 'state_machine',
                    'marking_store' => [
                        'type' => 'method',
                        'property' => 'statusValue',
                    ],
                    'audit_trail' => [
                        'enabled' => true,
                    ],
                    'supports' => [
                        Invoice::class,
                    ],
                    'places' => [
                        InvoiceStatus::New->value,
                        InvoiceStatus::Draft->value,
                        InvoiceStatus::Pending->value,
                        InvoiceStatus::Active->value,
                        InvoiceStatus::Overdue->value,
                        InvoiceStatus::Cancelled->value,
                        InvoiceStatus::Archived->value,
                        InvoiceStatus::Paid->value,
                    ],
                    'transitions' => [
                        [
                            'name' => InvoiceGraph::TRANSITION_NEW,
                            'from' => [InvoiceStatus::New->value],
                            'to' => [InvoiceStatus::Draft->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_ACCEPT,
                            'from' => [InvoiceStatus::New->value, InvoiceStatus::Draft->value],
                            'to' => [InvoiceStatus::Pending->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_CANCEL,
                            'from' => [InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value],
                            'to' => [InvoiceStatus::Cancelled->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_OVERDUE,
                            'from' => [InvoiceStatus::Pending->value],
                            'to' => [InvoiceStatus::Overdue->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_PAY,
                            'from' => [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value],
                            'to' => [InvoiceStatus::Paid->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_REOPEN,
                            'from' => [InvoiceStatus::Cancelled->value],
                            'to' => [InvoiceStatus::Draft->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_ARCHIVE,
                            'from' => [InvoiceStatus::New->value, InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value, InvoiceStatus::Paid->value],
                            'to' => [InvoiceStatus::Archived->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_EDIT,
                            'from' => [InvoiceStatus::Cancelled->value, InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value],
                            'to' => [InvoiceStatus::Draft->value],
                        ],
                    ],
                ],
                'recurring_invoice' => [
                    'type' => 'state_machine',
                    'marking_store' => [
                        'type' => 'method',
                        'property' => 'statusValue',
                    ],
                    'audit_trail' => [
                        'enabled' => true,
                    ],
                    'supports' => [
                        RecurringInvoice::class,
                    ],
                    'places' => [
                        RecurringInvoiceStatus::New->value,
                        RecurringInvoiceStatus::Draft->value,
                        RecurringInvoiceStatus::Active->value,
                        RecurringInvoiceStatus::Paused->value,
                        RecurringInvoiceStatus::Complete->value,
                        RecurringInvoiceStatus::Cancelled->value,
                        RecurringInvoiceStatus::Archived->value,
                    ],
                    'transitions' => [
                        [
                            'name' => InvoiceGraph::TRANSITION_NEW,
                            'from' => [RecurringInvoiceStatus::New->value],
                            'to' => [RecurringInvoiceStatus::Draft->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_ACTIVATE,
                            'from' => [RecurringInvoiceStatus::New->value, RecurringInvoiceStatus::Draft->value],
                            'to' => [RecurringInvoiceStatus::Active->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_CANCEL,
                            'from' => [RecurringInvoiceStatus::Draft->value, RecurringInvoiceStatus::Active->value],
                            'to' => [RecurringInvoiceStatus::Cancelled->value],
                        ],
                        [
                            'name' => 'complete',
                            'from' => [RecurringInvoiceStatus::Active->value],
                            'to' => [RecurringInvoiceStatus::Complete->value],
                        ],
                        [
                            'name' => InvoiceGraph::TRANSITION_ARCHIVE,
                            'from' => [RecurringInvoiceStatus::New->value, RecurringInvoiceStatus::Draft->value, RecurringInvoiceStatus::Cancelled->value, RecurringInvoiceStatus::Active->value, RecurringInvoiceStatus::Paused->value],
                            'to' => [RecurringInvoiceStatus::Archived->value],
                        ],
                        [
                            'name' => 'edit',
                            'from' => [RecurringInvoiceStatus::Cancelled->value, RecurringInvoiceStatus::Draft->value, RecurringInvoiceStatus::Active->value, RecurringInvoiceStatus::Paused->value],
                            'to' => [RecurringInvoiceStatus::Draft->value],
                        ],
                        [
                            'name' => 'pause',
                            'from' => [RecurringInvoiceStatus::Active->value],
                            'to' => [RecurringInvoiceStatus::Paused->value],
                        ],
                        [
                            'name' => 'resume',
                            'from' => [RecurringInvoiceStatus::Paused->value],
                            'to' => [RecurringInvoiceStatus::Active->value],
                        ],
                    ],
                ],
                'quote' => [
                    'type' => 'state_machine',
                    'marking_store' => [
                        'type' => 'method',
                        'property' => 'statusValue',
                    ],
                    'audit_trail' => [
                        'enabled' => true,
                    ],
                    'supports' => [
                        Quote::class,
                    ],
                    'places' => [
                        QuoteStatus::New->value,
                        QuoteStatus::Draft->value,
                        QuoteStatus::Pending->value,
                        QuoteStatus::Cancelled->value,
                        QuoteStatus::Archived->value,
                        QuoteStatus::Accepted->value,
                        QuoteStatus::Declined->value,
                    ],
                    'transitions' => [
                        [
                            'name' => QuoteGraph::TRANSITION_NEW,
                            'from' => [QuoteStatus::New->value, QuoteStatus::Cancelled->value],
                            'to' => [QuoteStatus::Draft->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_SEND,
                            'from' => [QuoteStatus::New->value, QuoteStatus::Draft->value],
                            'to' => [QuoteStatus::Pending->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_PUBLISH,
                            'from' => [QuoteStatus::New->value, QuoteStatus::Draft->value],
                            'to' => [QuoteStatus::Pending->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_CANCEL,
                            'from' => [QuoteStatus::Draft->value, QuoteStatus::Pending->value],
                            'to' => [QuoteStatus::Cancelled->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_DECLINE,
                            'from' => [QuoteStatus::New->value, QuoteStatus::Draft->value, QuoteStatus::Pending->value],
                            'to' => [QuoteStatus::Declined->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_ACCEPT,
                            'from' => [QuoteStatus::Pending->value],
                            'to' => [QuoteStatus::Accepted->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_REOPEN,
                            'from' => [QuoteStatus::Declined->value, QuoteStatus::Cancelled->value],
                            'to' => [QuoteStatus::Draft->value],
                        ],
                        [
                            'name' => QuoteGraph::TRANSITION_ARCHIVE,
                            'from' => [QuoteStatus::New->value, QuoteStatus::Draft->value, QuoteStatus::Cancelled->value, QuoteStatus::Accepted->value, QuoteStatus::Declined->value, QuoteStatus::Pending->value],
                            'to' => [QuoteStatus::Archived->value],
                        ],
                    ],
                ],
                'bill' => [
                    'type' => 'state_machine',
                    'marking_store' => [
                        'type' => 'method',
                        'property' => 'statusValue',
                    ],
                    'audit_trail' => [
                        'enabled' => true,
                    ],
                    'supports' => [
                        Bill::class,
                    ],
                    'places' => [
                        BillStatus::Draft->value,
                        BillStatus::Pending->value,
                        BillStatus::Paid->value,
                        BillStatus::Overdue->value,
                        BillStatus::Cancelled->value,
                        BillStatus::Archived->value,
                    ],
                    'transitions' => [
                        [
                            'name' => BillGraph::TRANSITION_CONFIRM,
                            'from' => [BillStatus::Draft->value],
                            'to' => [BillStatus::Pending->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_PAY,
                            'from' => [BillStatus::Pending->value, BillStatus::Overdue->value],
                            'to' => [BillStatus::Paid->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_OVERDUE,
                            'from' => [BillStatus::Pending->value],
                            'to' => [BillStatus::Overdue->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_CANCEL,
                            'from' => [BillStatus::Draft->value, BillStatus::Pending->value, BillStatus::Overdue->value],
                            'to' => [BillStatus::Cancelled->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_REOPEN,
                            'from' => [BillStatus::Cancelled->value],
                            'to' => [BillStatus::Draft->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_ARCHIVE,
                            'from' => [BillStatus::Draft->value, BillStatus::Cancelled->value, BillStatus::Paid->value],
                            'to' => [BillStatus::Archived->value],
                        ],
                        [
                            'name' => BillGraph::TRANSITION_EDIT,
                            'from' => [BillStatus::Cancelled->value, BillStatus::Pending->value, BillStatus::Overdue->value],
                            'to' => [BillStatus::Draft->value],
                        ],
                    ],
                ],
                'credit_note' => [
                    'type' => 'state_machine',
                    'marking_store' => [
                        'type' => 'method',
                        'property' => 'statusValue',
                    ],
                    'audit_trail' => [
                        'enabled' => true,
                    ],
                    'supports' => [
                        CreditNote::class,
                    ],
                    // No cancel, and no edit: a credit note that has been handed
                    // over is as fixed as the invoice it corrects. One raised in
                    // error is corrected by an invoice, not unwound here.
                    'places' => [
                        CreditNoteStatus::Draft->value,
                        CreditNoteStatus::Issued->value,
                        CreditNoteStatus::Settled->value,
                    ],
                    'transitions' => [
                        [
                            'name' => CreditNoteGraph::TRANSITION_ISSUE,
                            'from' => [CreditNoteStatus::Draft->value],
                            'to' => [CreditNoteStatus::Issued->value],
                        ],
                        [
                            'name' => CreditNoteGraph::TRANSITION_SETTLE,
                            'from' => [CreditNoteStatus::Issued->value],
                            'to' => [CreditNoteStatus::Settled->value],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
