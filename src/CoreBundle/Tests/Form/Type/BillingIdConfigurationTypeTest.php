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

namespace Augias\CoreBundle\Tests\Form\Type;

use Augias\CoreBundle\Form\Type\BillingIdConfigurationType;
use Augias\CoreBundle\Generator\BillingIdGenerator\IdGeneratorInterface;
use Augias\CoreBundle\Generator\BillingIdGenerator\SequentialIdGeneratorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\OptionsResolver\OptionsResolver;

#[CoversClass(BillingIdConfigurationType::class)]
final class BillingIdConfigurationTypeTest extends TestCase
{
    public function testOffersEveryStrategyByDefault(): void
    {
        $options = $this->resolve([]);

        self::assertSame(
            [
                'Auto Increment' => 'auto_increment',
                'Random Number' => 'random_number',
                'Uuid' => 'uuid',
            ],
            $options['choices'],
        );
    }

    /**
     * A credit note has to carry a gapless number, so the strategies that
     * cannot produce one are never put on the screen.
     */
    public function testOffersOnlySequentialStrategiesWhenAsked(): void
    {
        $options = $this->resolve(['sequential_only' => true]);

        self::assertSame(['Auto Increment' => 'auto_increment'], $options['choices']);
    }

    public function testRejectsANonBooleanFlag(): void
    {
        $this->expectExceptionMessageMatches('/sequential_only/');

        $this->resolve(['sequential_only' => 'yes']);
    }

    /**
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private function resolve(array $given): array
    {
        $sequential = $this->createStub(SequentialIdGeneratorInterface::class);
        $random = $this->createStub(IdGeneratorInterface::class);
        $uuid = $this->createStub(IdGeneratorInterface::class);

        $type = new BillingIdConfigurationType(
            new ServiceLocator([
                'auto_increment' => static fn (): IdGeneratorInterface => $sequential,
                'random_number' => static fn (): IdGeneratorInterface => $random,
                'uuid' => static fn (): IdGeneratorInterface => $uuid,
            ]),
            new ServiceLocator([
                'auto_increment' => static fn (): SequentialIdGeneratorInterface => $sequential,
            ]),
        );

        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        return $resolver->resolve($given);
    }
}
