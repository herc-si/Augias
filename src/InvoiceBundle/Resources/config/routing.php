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

use Augias\InvoiceBundle\Action\CloneInvoice;
use Augias\InvoiceBundle\Action\CloneRecurringInvoice;
use Augias\InvoiceBundle\Action\Create;
use Augias\InvoiceBundle\Action\CreateRecurring;
use Augias\InvoiceBundle\Action\CreditNote\Allocate as CreditNoteAllocate;
use Augias\InvoiceBundle\Action\CreditNote\Create as CreditNoteCreate;
use Augias\InvoiceBundle\Action\CreditNote\Edit as CreditNoteEdit;
use Augias\InvoiceBundle\Action\CreditNote\Index as CreditNoteIndex;
use Augias\InvoiceBundle\Action\CreditNote\Send as CreditNoteSend;
use Augias\InvoiceBundle\Action\CreditNote\Transition as CreditNoteTransition;
use Augias\InvoiceBundle\Action\CreditNote\View as CreditNoteView;
use Augias\InvoiceBundle\Action\DisbursementNote\View as DisbursementNoteView;
use Augias\InvoiceBundle\Action\DisbursementReceipt\Delete as DisbursementReceiptDelete;
use Augias\InvoiceBundle\Action\DisbursementReceipt\Download as DisbursementReceiptDownload;
use Augias\InvoiceBundle\Action\DisbursementReceipt\Upload as DisbursementReceiptUpload;
use Augias\InvoiceBundle\Action\Edit;
use Augias\InvoiceBundle\Action\EditRecurring;
use Augias\InvoiceBundle\Action\Fields;
use Augias\InvoiceBundle\Action\Index;
use Augias\InvoiceBundle\Action\RecurringIndex;
use Augias\InvoiceBundle\Action\RecurringTransition;
use Augias\InvoiceBundle\Action\SendManualReminder;
use Augias\InvoiceBundle\Action\Transition;
use Augias\InvoiceBundle\Action\Transition\Send;
use Augias\InvoiceBundle\Action\View;
use Augias\InvoiceBundle\Action\ViewRecurring;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_invoices_index', '/')
        ->controller(Index::class);

    $routingConfigurator
        ->add('_invoices_index_recurring', '/recurring')
        ->controller(RecurringIndex::class);

    $routingConfigurator
        ->add('_invoices_create', '/create/{client}')
        ->controller(Create::class)
        ->defaults(['client' => null]);

    $routingConfigurator
        ->add('_invoices_create_recurring', '/recurring/create/{client}')
        ->controller(CreateRecurring::class)
        ->defaults(['client' => null]);

    $routingConfigurator
        ->add('_invoices_get_fields', '/fields/get/{currency}')
        ->controller(Fields::class);

    $routingConfigurator
        ->add('_invoices_edit', '/edit/{id}')
        ->controller(Edit::class);

    $routingConfigurator
        ->add('_invoices_edit_recurring', '/recurring/edit/{id}')
        ->controller(EditRecurring::class);

    $routingConfigurator
        ->add('_invoices_view', '/view/{id}.{_format}')
        ->controller(View::class)
        ->defaults(['_format' => 'html'])
        ->requirements(['_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_invoices_view_recurring', '/recurring/view/{id}')
        ->controller(ViewRecurring::class);

    $routingConfigurator
        ->add('_invoices_clone', '/clone/{id}')
        ->controller(CloneInvoice::class);

    $routingConfigurator
        ->add('_invoices_clone_recurring', '/clone-recurring/{id}')
        ->controller(CloneRecurringInvoice::class);

    $routingConfigurator
        ->add('_send_invoice', '/action/send/{id}')
        ->controller(Send::class);

    $routingConfigurator
        ->add('_send_manual_reminder', '/action/send-reminder/{id}')
        ->methods(['POST'])
        ->controller(SendManualReminder::class);

    $routingConfigurator
        ->add('_invoices_disbursement_note', '/view/{id}/disbursement-note.pdf')
        ->methods(['GET'])
        ->controller(DisbursementNoteView::class);

    $routingConfigurator
        ->add('_invoices_disbursement_receipt_upload', '/disbursement/{id}/receipt')
        ->methods(['POST'])
        ->controller(DisbursementReceiptUpload::class);

    $routingConfigurator
        ->add('_invoices_disbursement_receipt_download', '/disbursement/receipt/{id}')
        ->methods(['GET'])
        ->controller(DisbursementReceiptDownload::class);

    $routingConfigurator
        ->add('_invoices_disbursement_receipt_delete', '/disbursement/receipt/{id}/delete')
        ->methods(['POST'])
        ->controller(DisbursementReceiptDelete::class);

    $routingConfigurator
        ->add('_action_invoice', '/action/{action}/{id}')
        ->controller(Transition::class);

    $routingConfigurator
        ->add('_action_recurring_invoice', '/recurring-action/{action}/{id}')
        ->controller(RecurringTransition::class);

    // Credit notes sit under the invoice prefix because that is where they
    // belong: a credit note corrects an invoice, and the two are read together.
    $routingConfigurator
        ->add('_credit_notes_index', '/credit-notes')
        ->controller(CreditNoteIndex::class);

    $routingConfigurator
        ->add('_credit_notes_create', '/credit-notes/create/{invoice}')
        ->controller(CreditNoteCreate::class)
        ->defaults(['invoice' => null]);

    $routingConfigurator
        ->add('_credit_notes_edit', '/credit-notes/edit/{id}')
        ->controller(CreditNoteEdit::class);

    $routingConfigurator
        ->add('_credit_notes_view', '/credit-notes/view/{id}.{_format}')
        ->controller(CreditNoteView::class)
        ->defaults(['_format' => 'html'])
        ->requirements(['_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_credit_notes_send', '/credit-notes/action/send/{id}')
        ->controller(CreditNoteSend::class);

    $routingConfigurator
        ->add('_credit_notes_allocate', '/credit-notes/allocate/{id}')
        ->methods(['POST'])
        ->controller(CreditNoteAllocate::class);

    $routingConfigurator
        ->add('_action_credit_note', '/credit-notes/action/{action}/{id}')
        ->controller(CreditNoteTransition::class);
};
