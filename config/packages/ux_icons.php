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

/**
 * Icons are files in this repository, never a request to a third party.
 *
 * The bundle's default is to fetch any icon it cannot find from
 * api.iconify.design while rendering the page. Nothing announced it — the icon
 * simply appeared — and 51 of the 204 icons this application names were
 * arriving that way. Three things were wrong with it, and only the first is
 * obvious:
 *
 *  - rendering a page depended on an external service being reachable. That
 *    took a CI run down, and it would take a customer's invoice screen down
 *    the same way;
 *  - it told a third party which pages an instance renders;
 *  - two names had been wrong for as long as they had existed
 *    (`tabler:device-check`, `tabler:currency-exchange` — neither is a real
 *    Tabler icon) and the fetch turned that into a silent blank.
 *
 * `ignore_not_found` is the other half, and it is deliberately not symmetric
 * with the environments below: in production a decorative icon must never be
 * able to take a page down. Not every icon name can be checked ahead of time —
 * PlatformUI's buttons build theirs as `'tabler:' ~ icon` from a component
 * property — so the one that slips through renders as nothing rather than as a
 * 500 on someone's screen.
 *
 * Adding an icon is `bin/console ux:icons:import tabler:<name>`, which writes
 * the file into assets/icons/ to be reviewed and committed like any other
 * source. `ux:icons:lock` imports in bulk but scans templates only: an icon
 * named in a PHP attribute — every dashboard widget names one — is invisible
 * to it, as is anything assembled at runtime.
 *
 * @see \Augias\CoreBundle\Tests\Asset\IconsAreInTheRepositoryTest
 */
return App::config([
    'ux_icons' => [
        'iconify' => [
            'on_demand' => false,
        ],
        'ignore_not_found' => true,
    ],
]);
