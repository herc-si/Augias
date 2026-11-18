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

use Augias\CoreBundle\AugiasCoreBundle;

// Session store is configurable via AUGIAS_MCP_SESSION_STORE
// (file | memory | cache | framework):
//   - file: on-disk (default, single-node deployments)
//   - memory: in-process (dev only; resets on restart)
//   - cache: PSR-16 cache pool — set AUGIAS_MCP_SESSION_CACHE_POOL
//     to a Redis-backed pool for multi-node deployments
//   - framework: Symfony session.handler (shares the app's session storage)
//
// The mcp-bundle reads these values at compile time, so env() placeholders
// can't be used here — read $_ENV / $_SERVER directly (symfony/runtime has
// already loaded .env by the time this config runs).
$env = static fn (string $name, string | int $default): string | int => $_ENV[$name] ?? $_SERVER[$name] ?? $default;

// The SDK's DNS rebinding protection lets only localhost through by default:
// every authenticated request from Claude on preprod was a 403 "Invalid Host
// header" (18/11/2026). It guards local servers that answer anyone; this one
// asks an OAuth bearer token of every request, behind a proxy that serves our
// own host names only. Off, unless AUGIAS_MCP_ALLOWED_HOSTS lists the hosts
// (comma-separated) to keep it on with.
$allowedHosts = array_values(array_filter(array_map('trim', explode(',', (string) $env('AUGIAS_MCP_ALLOWED_HOSTS', '')))));

return App::config([
    'mcp' => [
        'app' => AugiasCoreBundle::APP_NAME,
        'version' => AugiasCoreBundle::VERSION,
        'description' => 'MCP server for ' . AugiasCoreBundle::APP_NAME,
        'client_transports' => [
            'stdio' => false,
            'http' => true,
        ],
        'http' => [
            'path' => '/_mcp',
            'allowed_hosts' => [] === $allowedHosts ? false : $allowedHosts,
            'session' => [
                'store' => (string) $env('AUGIAS_MCP_SESSION_STORE', 'file'),
                'cache_pool' => (string) $env('AUGIAS_MCP_SESSION_CACHE_POOL', 'cache.mcp.sessions'),
                'prefix' => (string) $env('AUGIAS_MCP_SESSION_PREFIX', 'mcp-'),
                'ttl' => (int) $env('AUGIAS_MCP_SESSION_TTL', 3600),
            ],
        ],
    ],
]);
