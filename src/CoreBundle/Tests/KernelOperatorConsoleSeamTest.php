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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use function class_exists;
use function dirname;
use function in_array;

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
     */
    public function testTheConsoleIsNotPartOfThisRepository(): void
    {
        // Not installed is the ordinary case, and an empty path is under no
        // directory, so both cases answer the same single assertion.
        $file = class_exists(Kernel::OPERATOR_CONSOLE_BUNDLE)
            ? (string) (new ReflectionClass(Kernel::OPERATOR_CONSOLE_BUNDLE))->getFileName()
            : '';

        self::assertStringStartsNotWith(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR,
            $file,
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
