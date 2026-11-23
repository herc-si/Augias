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

use Augias\UserBundle\Security\TrustedDevices;

// The platform prepends the rest of this bundle's configuration (trusted
// devices for 30 days, backup codes, form renderers). Augias's own: the pages
// asking for the code, so they match the rest of the sign-in, and the
// trusted-device manager, which records each device so its holder can see it.
return App::config([
    'scheb_two_factor' => [
        'trusted_device' => [
            'manager' => TrustedDevices::class,
        ],
        'totp' => [
            'template' => '@AugiasUser/Security/TwoFactor/totp.html.twig',
        ],
        'email' => [
            'template' => '@AugiasUser/Security/TwoFactor/email.html.twig',
        ],
    ],
]);
