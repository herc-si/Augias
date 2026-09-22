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

use Augias\CoreBundle\Action\Category\Add as CategoryAdd;
use Augias\CoreBundle\Action\Category\Edit as CategoryEdit;
use Augias\CoreBundle\Action\Category\Index as CategoryIndex;
use Augias\CoreBundle\Action\CreateCompany;
use Augias\CoreBundle\Action\DeleteCompany;
use Augias\CoreBundle\Action\Search;
use Augias\CoreBundle\Action\SearchSuggestions;
use Augias\CoreBundle\Action\SelectCompany;
use Augias\CoreBundle\Action\ViewBilling;
use Augias\CoreBundle\Export\Action\DownloadExport;
use Augias\CoreBundle\Export\Action\ListExports;
use Augias\CoreBundle\Export\Action\RequestExport;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_home', '/')
        ->controller([RedirectController::class, 'redirectAction'])
        ->defaults(
            [
                'route' => '_dashboard',
                'permanent' => true,
            ]
        );

    $routingConfigurator
        ->add('_view_quote_external', '/view/quote/{uuid}.{_format}')
        ->controller([ViewBilling::class, 'quoteAction'])
        ->defaults(['_format' => 'html'])
        ->requirements(['uuid' => '[a-zA-Z0-9-]{36}', '_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_view_invoice_external', '/view/invoice/{uuid}.{_format}')
        ->controller([ViewBilling::class, 'invoiceAction'])
        ->defaults(['_format' => 'html'])
        ->requirements(['uuid' => '[a-zA-Z0-9-]{36}', '_format' => 'html|pdf']);

    $routingConfigurator
        ->add('_select_company', '/select-company')
        ->controller(SelectCompany::class);

    $routingConfigurator
        ->add('_switch_company', '/select-company/{id}')
        ->controller([SelectCompany::class, 'switchCompany']);

    $routingConfigurator
        ->add('_create_company', '/create-company')
        ->controller(CreateCompany::class);

    $routingConfigurator
        ->add('_delete_company', '/delete-company')
        ->controller(DeleteCompany::class)
        ->methods(['POST'])
    ;

    $routingConfigurator
        ->add('_search', '/search')
        ->controller(Search::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_search_suggestions', '/search/suggestions')
        ->controller(SearchSuggestions::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_export_list', '/profile/exports')
        ->controller(ListExports::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_export_request', '/profile/exports')
        ->controller(RequestExport::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_export_download', '/profile/exports/{id}/download')
        ->controller(DownloadExport::class)
        ->methods(['GET']);

    // One list of categories for purchases and the catalogue alike; the two
    // used to have a settings screen each. See CoreBundle\Entity\Category.
    $routingConfigurator
        ->add('_categories_index', '/categories')
        ->controller(CategoryIndex::class);

    $routingConfigurator
        ->add('_categories_add', '/categories/add')
        ->controller(CategoryAdd::class);

    $routingConfigurator
        ->add('_categories_edit', '/categories/edit/{id}')
        ->controller(CategoryEdit::class);
};
