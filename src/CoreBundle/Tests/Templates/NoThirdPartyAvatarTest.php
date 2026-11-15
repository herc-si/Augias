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

namespace Augias\CoreBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use function dirname;

/**
 * The signed-in user's avatar is drawn by the application. It used to be
 * fetched from gravatar.com with the MD5 of the e-mail address, which told a
 * third party, on every page, who was signed in and from where.
 */
final class NoThirdPartyAvatarTest extends TestCase
{
    public function testNoTemplateAsksGravatarForAnAvatar(): void
    {
        $templates = new Finder()->files()->in(dirname(__DIR__, 3))->path('Resources/views')->name('*.twig')->contains('/gravatar/i');

        $found = [];
        foreach ($templates as $template) {
            if (basename($template->getFilename()) === '_user_avatar.html.twig') {
                continue; // names the service in the comment that explains its removal
            }

            $found[] = $template->getRelativePathname();
        }

        self::assertSame([], $found);
    }
}
