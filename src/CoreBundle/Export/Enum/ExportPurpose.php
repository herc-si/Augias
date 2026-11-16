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

namespace Augias\CoreBundle\Export\Enum;

/**
 * Why an export was made, which decides who may take it.
 */
enum ExportPurpose: string
{
    /** Someone asked for it from their profile; it is theirs alone. */
    case Requested = 'requested';

    /**
     * Made because the company is closing: what its people have to keep once
     * it is gone. Anyone who runs the company may take it.
     */
    case Closure = 'closure';
}
