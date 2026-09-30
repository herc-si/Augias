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

namespace Augias\CoreBundle\Twig\Extension;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;
use function trim;

/**
 * `instance_label()`: the name an instance shows on every page, from
 * AUGIAS_INSTANCE_LABEL — "TEST" on a test server, so that it is never taken
 * for the one clients use. Empty everywhere else, and then nothing shows.
 *
 * @see \Augias\CoreBundle\Tests\Functional\InstanceLabelTest
 */
final readonly class InstanceLabelExtension
{
    public function __construct(
        #[Autowire(env: 'AUGIAS_INSTANCE_LABEL')]
        private string $label,
    ) {
    }

    #[AsTwigFunction('instance_label')]
    public function label(): string
    {
        return trim($this->label);
    }
}
