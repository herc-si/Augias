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

namespace Augias\CoreBundle\Tests\Asset;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_file;
use function ksort;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * Every icon this application names is a file in this repository.
 *
 * Symfony UX Icons will otherwise fetch an unknown icon from api.iconify.design
 * while rendering the page, which is how 51 of the 204 icons here used to
 * arrive. Nothing announced it — the icon simply appeared — until the day the
 * service was unreachable and a CI run failed on an unrelated test, which is
 * also the day a customer's screen would have failed.
 *
 * config/packages/ux_icons.php turns that fetch off, so a missing icon is now
 * an error rather than a silent request. This test is what keeps the set
 * complete in the first place, and it catches the case the tooling cannot:
 * `ux:icons:lock` scans templates only, so an icon named in a PHP attribute —
 * every dashboard widget names one — is invisible to it.
 *
 * It also catches names that were never real. `tabler:device-check`,
 * `tabler:currency-exchange` and `power-off` had each been misspelled for as
 * long as they had existed, and rendered nothing at all after a pointless round
 * trip.
 *
 * What it deliberately does not try to cover is the name assembled at runtime:
 * PlatformUI's buttons and this application's data grids both render
 * `'tabler:' ~ icon` from a property, and the strings feeding them cannot be
 * told apart from any other short string by reading the source — `offline` and
 * `paypal_express_checkout` sit in the same position and are gateway names.
 * Those are covered by rendering instead: config/packages/test/ux_icons.php
 * turns a missing icon into an error, so any page a test renders proves its own
 * icons. That is how `tabler:logout`, `tabler:compass` and `tabler:ban` were
 * found, none of which appear anywhere in the source as such.
 */
#[CoversNothing]
final class IconsAreInTheRepositoryTest extends TestCase
{
    /**
     * Extensions worth reading. Icon names live in templates and in PHP
     * attributes, and the front end names a few of its own.
     */
    private const array EXTENSIONS = ['twig', 'php', 'ts', 'js'];

    private const array SCANNED = ['src', 'templates', 'assets'];

    /**
     * A floor, not a target: without it this test would pass by scanning
     * nothing at all the day a path is renamed.
     */
    private const int FEWEST_ICONS_EXPECTED = 150;

    public function testEveryIconNamedInTheCodebaseHasBeenImported(): void
    {
        $root = dirname(__DIR__, 4);
        $used = $this->iconsNamedIn($root);

        self::assertGreaterThanOrEqual(
            self::FEWEST_ICONS_EXPECTED,
            count($used),
            'Far fewer icons were found than this application uses — the scan is looking in the wrong place.',
        );

        $missing = [];

        foreach ($used as $name => $where) {
            if (! is_file(sprintf('%s/assets/icons/tabler/%s.svg', $root, $name))) {
                $missing[$name] = $where;
            }
        }

        self::assertSame(
            [],
            $missing,
            "These icons are named in the code but are not in assets/icons/tabler.\n"
            . "Import them — `bin/console ux:icons:import tabler:<name>` — or correct the name if it was never a real Tabler icon.\n"
            . $this->describe($missing),
        );
    }

    /**
     * @return array<string, string> icon name => the first file that names it
     */
    private function iconsNamedIn(string $root): array
    {
        $found = [];

        foreach (self::SCANNED as $directory) {
            $path = $root . '/' . $directory;

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                // Its own source names every icon it is asserting about.
                if (str_contains($file->getPathname(), 'IconsAreInTheRepositoryTest')) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (false === $contents) {
                    continue;
                }

                preg_match_all('/tabler:([a-z0-9][a-z0-9-]*)/', $contents, $matches);

                foreach ($matches[1] as $name) {
                    $found[$name] ??= mb_substr($file->getPathname(), mb_strlen($root) + 1);
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @param array<string, string> $missing
     */
    private function describe(array $missing): string
    {
        $lines = '';

        foreach ($missing as $name => $where) {
            $lines .= sprintf("  tabler:%s — %s\n", $name, $where);
        }

        return $lines;
    }
}
