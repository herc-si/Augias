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

// The platform prepends the rest of this bundle's configuration (trusted
// devices for 30 days, backup codes, form renderers); only the pages asking
// for the code are Augias's own, so they match the rest of the sign-in.
return App::config([
    'scheb_two_factor' => [
        'totp' => [
            'template' => '@AugiasUser/Security/TwoFactor/totp.html.twig',
        ],
        'email' => [
            'template' => '@AugiasUser/Security/TwoFactor/email.html.twig',
        ],
    ],
]);
