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

namespace Augias\McpBundle\Repository;

use Augias\McpBundle\OAuth\ScopeEntity;
use Augias\McpBundle\Security\McpScope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

final class McpScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (! \in_array($identifier, McpScope::values(), true)) {
            return null;
        }

        return new ScopeEntity($identifier);
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $filtered = [];

        foreach ($scopes as $scope) {
            if ($scope instanceof ScopeEntityInterface && \in_array($scope->getIdentifier(), McpScope::values(), true)) {
                $filtered[$scope->getIdentifier()] = $scope;
            }
        }

        // write access and finance access each imply read access
        if ((isset($filtered[McpScope::Write->value]) || isset($filtered[McpScope::Finance->value])) && ! isset($filtered[McpScope::Read->value])) {
            $filtered[McpScope::Read->value] = new ScopeEntity(McpScope::Read->value);
        }

        return array_values($filtered);
    }
}
