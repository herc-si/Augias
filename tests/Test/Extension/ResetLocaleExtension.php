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

namespace Augias\Test\Extension;

use Locale;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Puts the process-wide locale back between tests.
 *
 * `Request::setLocale()` calls `Locale::setDefault()`, so any test that builds a
 * request in French leaves the whole process in French — including every test
 * that runs after it. That was harmless until dates started being rendered
 * through ICU, which reads exactly that default: a snapshot then came out as
 * "4 juil." where it expected "Jul 4", depending on the order the suite happened
 * to pick that run. Two of fourteen database jobs, and never locally.
 *
 * Resetting per test rather than telling each offender to clean up: the leak is
 * a property of Symfony's Request, not of any one test, and the next one to
 * trip over it would have no reason to suspect it.
 */
final class ResetLocaleExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $default = Locale::getDefault();

        $facade->registerSubscriber(
            new class($default) implements PreparationStartedSubscriber {
                public function __construct(
                    private readonly string $default,
                ) {
                }

                public function notify(PreparationStarted $event): void
                {
                    Locale::setDefault($this->default);
                }
            }
        );
    }
}
