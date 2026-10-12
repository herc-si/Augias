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

namespace Augias\CoreBundle\Tests\Enum;

use Augias\CoreBundle\Enum\CustomFieldTarget;
use PHPUnit\Framework\TestCase;

final class CustomFieldTargetTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame('CLIENT', CustomFieldTarget::CLIENT->value);
        self::assertSame('CONTACT', CustomFieldTarget::CONTACT->value);
    }

    public function testLabel(): void
    {
        self::assertSame('custom_field.target.client', CustomFieldTarget::CLIENT->label());
        self::assertSame('custom_field.target.contact', CustomFieldTarget::CONTACT->label());
    }
}
