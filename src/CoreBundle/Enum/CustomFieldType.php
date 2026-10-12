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

enum CustomFieldType: string
{
    case TEXT = 'text';
    case TEXTAREA = 'textarea';
    case NUMBER = 'number';
    case DATE = 'date';
    case EMAIL = 'email';
    case URL = 'url';
    case CHECKBOX = 'checkbox';
    case SELECT = 'select';
    case MULTI_SELECT = 'multi_select';

    public function label(): string
    {
        // A translation key: the settings screen shows it in the user's language.
        return 'custom_field.type.' . $this->value;
    }

    public function requiresOptions(): bool
    {
        return $this === self::SELECT || $this === self::MULTI_SELECT;
    }
}
