<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Model;

use Augias\AccountingBundle\Entity\LedgerEntry;
use DateTimeImmutable;

/**
 * What taking a company's history into its books would add: the entries,
 * built but not written, from the day it starts.
 *
 * @see \Augias\AccountingBundle\Service\BooksCatchUp
 */
final readonly class CatchUpPlan
{
    /**
     * @param DateTimeImmutable      $from          the day it starts, never before $earliestStart
     * @param DateTimeImmutable|null $earliestStart the day after the lock date, null when nothing is locked
     * @param list<LedgerEntry>      $entries       in date order
     */
    public function __construct(
        public DateTimeImmutable $from,
        public ?DateTimeImmutable $earliestStart,
        public array $entries,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
