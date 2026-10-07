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

namespace Augias\NotificationBundle\Attribute;

use Attribute;
use Augias\NotificationBundle\Enum\NotificationCategory;

/**
 * @see \Augias\NotificationBundle\Tests\Attribute\AsNotificationTest
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsNotification
{
    public function __construct(
        public string $name,
        public string $title = '',
        public string $description = '',
        public string $icon = 'tabler:bell',
        public NotificationCategory $category = NotificationCategory::OTHER,
        /**
         * Sent by e-mail to the members who can bill until they say otherwise,
         * rather than to no one until they ask: for what goes wrong and needs
         * someone to act. Only a notification that carries its company can be.
         */
        public bool $defaultOn = false,
    ) {
    }
}
