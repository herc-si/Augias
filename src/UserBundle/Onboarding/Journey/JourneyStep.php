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

namespace Augias\UserBundle\Onboarding\Journey;

/**
 * One step of the setup journey, as it stands for the person going through it.
 */
final readonly class JourneyStep
{
    public function __construct(
        public SetupStep $step,
        public bool $done,
        public bool $skipped,
        public bool $skippable,
    ) {
    }

    /**
     * Nothing more is asked of it: done, or left for later.
     */
    public function isSettled(): bool
    {
        return $this->done || $this->skipped;
    }
}
