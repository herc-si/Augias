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

namespace Augias\CoreBundle;

use Augias\CoreBundle\DependencyInjection\Compiler\SubscriberResolverPass;
use Augias\CoreBundle\Search\ResultFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AugiasCoreBundle extends Bundle
{
    public const string VERSION = '4.0.0-beta1';

    public const string APP_NAME = 'Augias';

    public const NAMESPACE = __NAMESPACE__;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new SubscriberResolverPass());

        $container->registerForAutoconfiguration(ResultFormatterInterface::class)
            ->addTag('augias.search.result_formatter');
    }
}
