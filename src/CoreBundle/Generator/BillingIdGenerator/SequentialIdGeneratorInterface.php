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

namespace Augias\CoreBundle\Generator\BillingIdGenerator;

/**
 * Marks a strategy that numbers documents in an unbroken ascending run.
 *
 * Some documents cannot be numbered any other way. A French credit note has to
 * carry a continuous, gapless number within its series, which rules out the
 * random, uuid, ulid and timestamp strategies — they are fine for an internal
 * reference, and useless as a document number an auditor can follow.
 */
interface SequentialIdGeneratorInterface extends IdGeneratorInterface
{
}
