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

namespace Augias\CoreBundle\Tests;

use Augias\AppMode;
use Augias\Kernel;
use Augias\Test\SaasKernel;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function class_exists;
use function dirname;
use function file_get_contents;
use function in_array;
use function str_contains;
use function strlen;
use function strrpos;
use function substr;

/**
 * The seam that lets a private operator console plug into this application.
 *
 * What is worth testing here is the *absence*: that this repository builds,
 * boots and ships without the package, on every install that is not HERC SI's
 * own. A seam that quietly became a dependency would break every self-hosted
 * install at once, and the CI that would have caught it is this test.
 *
 * The assertions are written so they hold whether or not the console happens
 * to be installed, because the ordinary way to develop it is to install it
 * into a checkout of this repository as a path repository. Asserting a flat
 * absence instead — as this test did at first — left that developer with a red
 * suite for as long as the package was installed, which teaches them to ignore
 * it, which is the opposite of what a guard is for.
 */
#[CoversClass(Kernel::class)]
final class KernelOperatorConsoleSeamTest extends TestCase
{
    /**
     * The console may be *installed* — that is how it is developed — but none
     * of it may be committed here. If this fails, a package meant to stay
     * private has landed in this repository, or the constant was pointed at a
     * class that lives in Augias, which turns the seam into an ordinary
     * dependency.
     *
     * Asked of the files rather than of the autoloader, deliberately. Where an
     * installed class came from is a fact about someone's vendor directory;
     * what is committed here is a fact about this repository, and it is the
     * one this test is named after. It is also the only form that says the
     * same thing whether or not the console happens to be installed.
     */
    public function testTheConsoleIsNotPartOfThisRepository(): void
    {
        $source = dirname(__DIR__, 2);
        $namespace = 'namespace ' . substr(
            Kernel::OPERATOR_CONSOLE_BUNDLE,
            0,
            (int) strrpos(Kernel::OPERATOR_CONSOLE_BUNDLE, '\\'),
        );

        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $namespace)) {
                $offenders[] = substr($file->getPathname(), strlen($source) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            Kernel::OPERATOR_CONSOLE_BUNDLE . ' is meant to live in a private package, not in this repository.',
        );
    }

    /**
     * The seam in both directions, in the one expression that stays true on a
     * plain checkout and on HERC SI's own: a SaaS kernel loads the console when
     * it is there, and cannot load it when it is not.
     */
    public function testASaasKernelRegistersTheConsoleExactlyWhenItIsInstalled(): void
    {
        $registered = in_array(
            Kernel::OPERATOR_CONSOLE_BUNDLE,
            self::bundleClasses(new SaasKernel('test', false)),
            true,
        );

        self::assertSame(class_exists(Kernel::OPERATOR_CONSOLE_BUNDLE), $registered);
    }

    /**
     * The invariant that has to hold whatever is installed: self-hosted never
     * loads it. This is the one that protects other people's deployments.
     */
    public function testASelfHostedKernelNeverRegistersIt(): void
    {
        self::assertNotContains(
            Kernel::OPERATOR_CONSOLE_BUNDLE,
            self::bundleClasses(new Kernel(AppMode::SELF_HOSTED, 'test', false)),
        );
    }

    /**
     * @return list<string>
     */
    private static function bundleClasses(Kernel $kernel): array
    {
        $classes = [];

        foreach ($kernel->registerBundles() as $bundle) {
            $classes[] = $bundle::class;
        }

        self::assertNotEmpty($classes);

        return $classes;
    }
}
