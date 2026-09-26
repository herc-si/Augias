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

namespace Augias\McpBundle\Mcp;

use Augias\McpBundle\Security\McpScope;
use Augias\UserBundle\Enum\CompanyPermission;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Enforces that the current MCP request carries the required OAuth scope.
 * Tools call {@see require()} at the top of every handler method.
 */
final readonly class McpScopeGuard
{
    public function __construct(
        private McpSecurityContext $context,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @throws ToolCallException if the current token lacks the required scope
     */
    public function require(McpScope $required): void
    {
        $granted = $this->context->getScopes();

        if (! McpScope::satisfies($granted, $required)) {
            throw new ToolCallException(sprintf(
                'This tool requires the "%s" scope. Granted: %s.',
                $required->value,
                $granted === [] ? '(none)' : implode(', ', $granted),
            ));
        }

        // A scope is what the person let the assistant do; their role is what
        // they may do themselves, and the assistant never gets more. An
        // accountant's assistant reads, whatever scope it was granted.
        $permission = McpScope::Write === $required ? CompanyPermission::BillingWrite : CompanyPermission::BillingRead;

        if (! $this->authorization->isGranted($permission->value)) {
            throw new ToolCallException(sprintf(
                'Your role in this company does not allow this: it needs the right to %s documents.',
                McpScope::Write === $required ? 'change' : 'read',
            ));
        }
    }
}
