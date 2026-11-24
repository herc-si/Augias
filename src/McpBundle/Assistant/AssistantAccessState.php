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

final readonly class AssistantAccessState
{
    /**
     * @param int|null $limit questions a month, null for no limit
     */
    public function __construct(
        public AssistantAccessStatus $status,
        public int $used,
        public ?int $limit,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === AssistantAccessStatus::Ready;
    }
}
