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
 * A missing icon is an error here, where someone is looking.
 *
 * Production swallows it, because an icon must never take a page down. That
 * kindness is wrong in front of a developer: it is what let two misspelled
 * names survive, and it would hide the ones no static check can find — names
 * assembled at runtime, like PlatformUI's `'tabler:' ~ icon`. Failing loudly
 * here is what surfaced `tabler:logout`, which nothing in the codebase spells
 * out and which every authenticated page renders.
 */
return App::config([
    'ux_icons' => [
        'ignore_not_found' => false,
    ],
]);
