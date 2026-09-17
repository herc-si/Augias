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

use Augias\AccountingBundle\Action\Book;
use Augias\AccountingBundle\Action\ClosePeriod;
use Augias\AccountingBundle\Action\CreatePeriod;
use Augias\AccountingBundle\Action\Declaration\Index as DeclarationIndex;
use Augias\AccountingBundle\Action\Declaration\Submit as DeclarationSubmit;
use Augias\AccountingBundle\Action\Declaration\View as DeclarationView;
use Augias\AccountingBundle\Action\Entry\Add;
use Augias\AccountingBundle\Action\Entry\Delete;
use Augias\AccountingBundle\Action\Entry\DeleteAttachment;
use Augias\AccountingBundle\Action\Entry\DownloadAttachment;
use Augias\AccountingBundle\Action\Entry\Edit;
use Augias\AccountingBundle\Action\Index;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_accounting_index', '/')
        ->controller(Index::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_accounting_book', '/book/{book}')
        ->controller(Book::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_accounting_entry_add', '/book/{book}/entry/add')
        ->controller(Add::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_accounting_entry_edit', '/entry/{id}/edit')
        ->controller(Edit::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_accounting_entry_delete', '/entry/{id}/delete')
        ->controller(Delete::class)
        ->methods(['DELETE', 'POST']);

    $routingConfigurator
        ->add('_accounting_attachment_download', '/entry/attachment/{id}')
        ->controller(DownloadAttachment::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_accounting_attachment_delete', '/entry/attachment/{id}/delete')
        ->controller(DeleteAttachment::class)
        ->methods(['DELETE', 'POST']);

    $routingConfigurator
        ->add('_accounting_declarations', '/declarations')
        ->controller(DeclarationIndex::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_accounting_declaration_view', '/declarations/{id}')
        ->controller(DeclarationView::class)
        ->methods(['GET']);

    // Recording a filing is one-way — see the action.
    $routingConfigurator
        ->add('_accounting_declaration_submit', '/declarations/{id}/submit/{kind}')
        ->controller(DeclarationSubmit::class)
        ->methods(['POST']);

    // POST only, and behind a token: it writes a period that nothing booked
    // into brought into being.
    $routingConfigurator
        ->add('_accounting_period_create', '/period/create')
        ->controller(CreatePeriod::class)
        ->methods(['POST']);

    // POST only, and behind a token: closing is one-way.
    $routingConfigurator
        ->add('_accounting_period_close', '/period/{id}/close')
        ->controller(ClosePeriod::class)
        ->methods(['POST']);
};
