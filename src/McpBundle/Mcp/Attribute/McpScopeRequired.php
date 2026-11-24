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

namespace Augias\McpBundle\Mcp\Attribute;

use Attribute;
use Augias\McpBundle\Security\McpScope;

/**
 * Declares a scope a tool method requires, once per scope: a tool that
 * writes in the books declares Finance and Write. Read on the method by the
 * built-in assistant to tell what reads from what writes
 * ({@see \Augias\McpBundle\Assistant\AssistantToolbox}); actual
 * enforcement happens via {@see \Augias\McpBundle\Mcp\McpScopeGuard}.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class McpScopeRequired
{
    public function __construct(
        public McpScope $scope
    ) {
    }
}
