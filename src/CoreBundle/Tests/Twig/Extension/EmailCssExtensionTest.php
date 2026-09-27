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

namespace Augias\CoreBundle\Tests\Twig\Extension;

use Augias\CoreBundle\Twig\Extension\EmailCssExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailCssExtension::class)]
final class EmailCssExtensionTest extends TestCase
{
    public function testKeepsOnlyTheMediaQueriesWithTheirNestedRules(): void
    {
        $css = <<<'CSS'
            table.body { width: 100%; }
            /* @media in a comment { is not one } */
            @media only screen and (max-width: 596px) {
                table.body .container { width: 95% !important; }
                td.small-12 { display: inline-block !important; }
            }
            p { margin: 0; }
            @media screen and (min-width: 596px) { a:hover { color: red; } }
            CSS;

        $result = new EmailCssExtension()->mediaQueries($css);

        self::assertStringContainsString('@media only screen and (max-width: 596px) {', $result);
        self::assertStringContainsString('width: 95% !important;', $result);
        self::assertStringContainsString('td.small-12 { display: inline-block !important; }', $result);
        self::assertStringContainsString('a:hover { color: red; }', $result);
        self::assertStringNotContainsString('table.body { width: 100%; }', $result);
        self::assertStringNotContainsString('p { margin: 0; }', $result);
        self::assertStringNotContainsString('comment', $result);
        self::assertSame(2, substr_count($result, '@media'));
    }

    public function testNoMediaQueryGivesNothing(): void
    {
        self::assertSame('', new EmailCssExtension()->mediaQueries('p { margin: 0; }'));
    }
}
