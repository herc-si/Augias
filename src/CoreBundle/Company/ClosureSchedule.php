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

namespace Augias\CoreBundle\Company;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something other than the owner that decides companies close — the end of
 * a hosted subscription. Brought up to date by the daily task just before
 * the due companies are deleted, so that none goes on a stale decision.
 */
#[AutoconfigureTag(self::TAG)]
interface ClosureSchedule
{
    public const string TAG = 'augias.closure_schedule';

    /**
     * Schedules the closures now due and calls off those no longer wanted.
     */
    public function reconcile(): void;
}
