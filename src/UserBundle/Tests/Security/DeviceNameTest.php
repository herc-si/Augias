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

namespace Augias\UserBundle\Tests\Security;

use Augias\UserBundle\Security\DeviceName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceName::class)]
final class DeviceNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function userAgents(): iterable
    {
        yield 'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0', 'Firefox · Linux'];
        yield 'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Chrome · Windows'];
        yield 'Edge, which also says Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'Edge · Windows'];
        yield 'Safari on an iPhone, which says Mac OS X' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 'Safari · iOS'];
        yield 'Chrome on Android, which says Linux' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36', 'Chrome · Android'];
        yield 'Safari on a Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 'Safari · macOS'];
        yield 'headless Chrome, which says it in one word' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.7339.16 Safari/537.36', 'Chrome · Linux'];
        yield 'a script' => ['curl/8.5.0', null];
        yield 'nothing' => [null, null];
    }

    #[DataProvider('userAgents')]
    public function testNamesTheBrowserAndTheSystem(?string $userAgent, ?string $expected): void
    {
        self::assertSame($expected, DeviceName::of($userAgent));
    }
}
