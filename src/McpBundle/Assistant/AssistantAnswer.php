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

namespace Augias\McpBundle\Assistant;

/**
 * What the assistant answered, which tools it ran to get there, and the
 * action it proposes, if it wants to change something.
 */
final readonly class AssistantAnswer
{
    /**
     * @param list<string> $toolsUsed
     */
    public function __construct(
        public string $text,
        public array $toolsUsed,
        public bool $gaveUp = false,
        public ?AssistantProposal $proposal = null,
    ) {
    }
}
