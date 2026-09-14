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

namespace Augias\CoreBundle\Form\Type;

use Augias\CoreBundle\Generator\BillingIdGenerator\IdGeneratorInterface;
use Augias\CoreBundle\Generator\BillingIdGenerator\SequentialIdGeneratorInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use function array_combine;
use function array_keys;
use function array_map;
use function str_replace;

/**
 * @extends AbstractType<mixed>
 */
final class BillingIdConfigurationType extends AbstractType
{
    /**
     * @param ServiceLocator<IdGeneratorInterface> $generators
     * @param ServiceLocator<SequentialIdGeneratorInterface> $sequentialGenerators
     */
    public function __construct(
        #[AutowireLocator(IdGeneratorInterface::class)]
        private readonly ServiceLocator $generators,
        #[AutowireLocator(SequentialIdGeneratorInterface::class)]
        private readonly ServiceLocator $sequentialGenerators,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Set on a document that may only be numbered in an unbroken run, such
        // as a credit note, so the random and uuid strategies are never offered
        // in the first place rather than rejected afterwards.
        $resolver->setDefined('sequential_only');
        $resolver->setDefault('sequential_only', false);
        $resolver->setAllowedTypes('sequential_only', 'bool');

        $resolver->setDefault('choices', function (Options $options): array {
            $locator = $options['sequential_only'] ? $this->sequentialGenerators : $this->generators;
            $services = array_keys($locator->getProvidedServices());

            return array_combine(
                array_map(static fn (string $name): string => ucwords(str_replace('_', ' ', $name)), $services),
                $services
            );
        });

        $resolver->setDefault('empty_data', 'Default');
    }

    #[Override]
    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
