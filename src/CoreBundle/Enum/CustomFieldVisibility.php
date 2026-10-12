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

namespace Augias\CoreBundle\Enum;

enum CustomFieldVisibility: string
{
    case INTERNAL = 'INTERNAL';
    case CLIENT_VISIBLE = 'CLIENT_VISIBLE';

    public function label(): string
    {
        // A translation key: the settings screen shows it in the user's language.
        return 'custom_field.visibility.' . strtolower($this->value);
    }
}
