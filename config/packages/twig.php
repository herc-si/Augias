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

return App::config([
    'twig' => [
        'debug' => param('kernel.debug'),
        'strict_variables' => param('kernel.debug'),
        'file_name_pattern' => '*.twig',
        'globals' => [
            // Where "contact support" leads on the hosted service's pages.
            // Empty, those links are left out.
            'saas_support_url' => '%env(AUGIAS_SAAS_SUPPORT_URL)%',
        ],
        'form_themes' => [
            '@AugiasNotification/Form/fields.html.twig',
            '@AugiasCore/Form/fields.html.twig',
            '@AugiasDataGrid/Form/fields.html.twig',
        ],
    ],
]);
