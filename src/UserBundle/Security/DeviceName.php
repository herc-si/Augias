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

namespace Augias\UserBundle\Security;

use function preg_match;
use function sprintf;

/**
 * "Firefox · Linux" from a user agent: enough for someone to recognise their
 * own devices in a list, without a parsing library for a label. The full
 * string stays available next to it.
 *
 * @see \Augias\UserBundle\Tests\Security\DeviceNameTest
 */
final class DeviceName
{
    /**
     * Order matters: Edge and Opera say "Chrome" too, and Chrome says
     * "Safari".
     */
    private const array BROWSERS = [
        '/\bEdg(e|A|iOS)?\//' => 'Edge',
        '/\b(OPR|Opera)\//' => 'Opera',
        '/\b(Firefox|FxiOS)\//' => 'Firefox',
        '/\bSamsungBrowser\//' => 'Samsung Internet',
        '/(Chrome|CriOS|Chromium)\//' => 'Chrome',
        '/\bSafari\//' => 'Safari',
    ];

    /**
     * Android says "Linux", and iOS says "Mac OS X".
     */
    private const array SYSTEMS = [
        '/\bAndroid\b/' => 'Android',
        '/\b(iPhone|iPad|iPod)\b/' => 'iOS',
        '/\bWindows\b/' => 'Windows',
        '/\bMac OS X\b|\bMacintosh\b/' => 'macOS',
        '/\bCrOS\b/' => 'ChromeOS',
        '/\bLinux\b/' => 'Linux',
    ];

    public static function of(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => sprintf('%s · %s', $browser, $system),
            default => $browser ?? $system,
        };
    }

    /**
     * @param array<string, string> $patterns
     */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
