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
 * Which Host headers this deployment will answer to, as regular expressions,
 * comma separated. Empty by default — Symfony's own default, and the only safe
 * one for an install whose shape is unknown.
 *
 * Worth setting wherever a host means anything. The Host header is
 * attacker-controlled, so `Request::getHost()` is a claim until this list makes
 * it a fact, and host-based routing, absolute URLs in emails and password-reset
 * links all rest on it.
 *
 * Read here rather than through `%env()%` because `framework.trusted_hosts`
 * wraps a bare string into a one-element list *before* an env placeholder is
 * resolved, so a `csv:` placeholder ends up as a list containing a list and the
 * container refuses to build. The cost is that changing this value needs a
 * cache clear, and that it must be a real environment variable rather than a
 * vault secret — the vault is only read once the kernel is running.
 */
$trustedHosts = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) ($_SERVER['AUGIAS_TRUSTED_HOSTS'] ?? '')),
), static fn (string $pattern): bool => $pattern !== ''));

return App::config([
    'framework' => [
        'secret' => env('AUGIAS_APP_SECRET'),
        'php_errors' => [
            'log' => true,
        ],
        'trusted_hosts' => $trustedHosts,
        'trusted_headers' => [
            'x-forwarded-for',
            'x-forwarded-proto',
            'x-forwarded-port',
            'x-forwarded-host',
            'x-forwarded-prefix',
        ],
        'session' => [
            'name' => 'AUGIAS_APP',
        ],
        'secrets' => [
            'enabled' => true,
            'vault_directory' => env('AUGIAS_CONFIG_DIR'),
        ],
    ],
]);
