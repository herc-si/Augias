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
use function class_exists;

/**
 * The seam that lets a private operator console plug into this application.
 *
 * What is worth testing here is the *absence*: that this repository builds,
 * boots and ships without the package, on every install that is not HERC SI's
 * own. A seam that quietly became a dependency would break every self-hosted
 * install at once, and the CI that would have caught it is this test.
 */
#[CoversClass(Kernel::class)]
final class KernelOperatorConsoleSeamTest extends TestCase
{
    public function testTheConsoleIsNotPartOfThisRepository(): void
    {
        // If this fails, a package meant to stay private has been committed
        // here — or the constant was pointed at a class that lives in Augias,
        // which turns the seam into an ordinary dependency.
        self::assertFalse(
            class_exists(Kernel::OPERATOR_CONSOLE_BUNDLE),
            Kernel::OPERATOR_CONSOLE_BUNDLE . ' is meant to live in a private package, not in this repository.',
        );
    }

    public function testASaasKernelRegistersItsBundlesWithoutTheConsoleInstalled(): void
    {
        self::assertNotContains(
            Kernel::OPERATOR_CONSOLE_BUNDLE,
            self::bundleClasses(new SaasKernel('test', false)),
        );
    }

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
