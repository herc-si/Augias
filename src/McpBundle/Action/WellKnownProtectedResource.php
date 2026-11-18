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

namespace Augias\McpBundle\Action;

use Augias\McpBundle\Security\McpScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/.well-known/oauth-protected-resource', name: 'mcp_well_known_protected_resource', methods: ['GET'])]
// RFC 9728 §3.1: the metadata of the resource https://host/_mcp is at the
// well-known path followed by the resource's path. Clients that follow it
// found a 404 here, and gave up (Claude, preprod, 18/11/2026).
#[Route(path: '/.well-known/oauth-protected-resource/_mcp', name: 'mcp_well_known_protected_resource_mcp', methods: ['GET'])]
final class WellKnownProtectedResource
{
    public function __invoke(Request $request): JsonResponse
    {
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

        return new JsonResponse([
            'resource' => $base . '/_mcp',
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => McpScope::values(),
        ]);
    }
}
