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

namespace Augias\CoreBundle\Tests\Generator;

use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\CoreBundle\Generator\BillingIdGenerator\IdGeneratorInterface;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(BillingIdGenerator::class)]
final class BillingIdGeneratorTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws JsonException
     */
    public function testGenerateWithDefaultStrategy(): void
    {
        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);
        $randomNumberGenerator = $this->createMock(IdGeneratorInterface::class);
        $timestampGenerator = $this->createMock(IdGeneratorInterface::class);

        $autoIncrementGenerator->expects(self::once())
            ->method('generate')
            ->willReturn('10');

        $randomNumberGenerator->expects(self::never())
            ->method('generate');

        $timestampGenerator->expects(self::never())
            ->method('generate');

        $systemConfig = $this->createMock(SystemConfig::class);

        $systemConfig->expects(self::exactly(3))
            ->method('get')
            ->willReturnMap([
                ['invoice/id_generation/strategy', 'auto_increment'],
                ['invoice/id_generation/id_prefix', ''],
                ['invoice/id_generation/id_suffix', ''],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(
                [
                    'auto_increment' => static fn () => $autoIncrementGenerator,
                    'random_number' => static fn () => $randomNumberGenerator,
                    'timestamp' => static fn () => $timestampGenerator,
                ],
            ),
            $systemConfig,
        );

        self::assertSame('10', $generator->generate(new Invoice()));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws JsonException
     */
    public function testGenerateWithCustomStrategy(): void
    {
        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);
        $randomNumberGenerator = $this->createMock(IdGeneratorInterface::class);
        $timestampGenerator = $this->createMock(IdGeneratorInterface::class);

        $autoIncrementGenerator->expects(self::never())
            ->method('generate');

        $randomNumberGenerator->expects(self::once())
            ->method('generate')
            ->willReturn('100');

        $timestampGenerator->expects(self::never())
            ->method('generate');

        $systemConfig = $this->createMock(SystemConfig::class);

        $systemConfig->expects(self::exactly(2))
            ->method('get')
            ->willReturnMap([
                ['invoice/id_generation/id_prefix', ''],
                ['invoice/id_generation/id_suffix', ''],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(
                [
                    'auto_increment' => static fn () => $autoIncrementGenerator,
                    'random_number' => static fn () => $randomNumberGenerator,
                    'timestamp' => static fn () => $timestampGenerator,
                ],
            ),
            $systemConfig,
        );

        self::assertSame('100', $generator->generate(new Invoice(), [], 'random_number'));
    }

    public function testGenerateWithPrefixAndSuffix(): void
    {
        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);
        $randomNumberGenerator = $this->createMock(IdGeneratorInterface::class);
        $timestampGenerator = $this->createMock(IdGeneratorInterface::class);

        $autoIncrementGenerator->expects(self::once())
            ->method('generate')
            ->willReturn('10');

        $randomNumberGenerator->expects(self::never())
            ->method('generate');

        $timestampGenerator->expects(self::never())
            ->method('generate');

        $systemConfig = $this->createMock(SystemConfig::class);

        $systemConfig->expects(self::exactly(3))
            ->method('get')
            ->willReturnMap([
                ['invoice/id_generation/strategy', null, 'auto_increment'],
                ['invoice/id_generation/id_prefix', null, 'INV-'],
                ['invoice/id_generation/id_suffix', null, '-00'],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(
                [
                    'auto_increment' => static fn () => $autoIncrementGenerator,
                    'random_number' => static fn () => $randomNumberGenerator,
                    'timestamp' => static fn () => $timestampGenerator,
                ],
            ),
            $systemConfig,
        );

        self::assertSame('INV-10-00', $generator->generate(new Invoice()));
    }

    /**
     * A year typed literally into the setting would still be last year's in
     * January, so it is written as a placeholder and resolved on every call.
     */
    public function testTheYearPlaceholderIsResolved(): void
    {
        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);

        $autoIncrementGenerator->expects(self::once())
            ->method('generate')
            ->willReturn('10');

        $systemConfig = $this->createStub(SystemConfig::class);

        $systemConfig->method('get')
            ->willReturnMap([
                ['invoice/id_generation/strategy', null, 'auto_increment'],
                ['invoice/id_generation/id_prefix', null, 'FACT-'],
                ['invoice/id_generation/id_suffix', null, '-{year}'],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(['auto_increment' => static fn () => $autoIncrementGenerator]),
            $systemConfig,
        );

        self::assertSame(
            'FACT-10-' . new DateTimeImmutable()->format('Y'),
            $generator->generate(new Invoice()),
        );
    }

    /**
     * The auto-increment strategy finds the previous number by cutting the
     * prefix and suffix off by length, so it has to be handed the resolved
     * affixes — `-{year}` is seven characters and `-2026` is five, and the
     * wrong one would slice the number itself.
     */
    public function testTheStrategyIsGivenTheResolvedAffixes(): void
    {
        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);
        $year = new DateTimeImmutable()->format('Y');

        $autoIncrementGenerator->expects(self::once())
            ->method('generate')
            ->with(
                self::isInstanceOf(Invoice::class),
                self::callback(static fn (array $options): bool => $options['prefix'] === 'FACT-'
                    && $options['suffix'] === '-' . $year),
            )
            ->willReturn('10');

        $systemConfig = $this->createStub(SystemConfig::class);

        $systemConfig->method('get')
            ->willReturnMap([
                ['invoice/id_generation/strategy', null, 'auto_increment'],
                ['invoice/id_generation/id_prefix', null, 'FACT-'],
                ['invoice/id_generation/id_suffix', null, '-{year}'],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(['auto_increment' => static fn () => $autoIncrementGenerator]),
            $systemConfig,
        );

        $generator->generate(new Invoice());
    }

    /**
     * A credit note is numbered in a series of its own — reading the invoice
     * settings would interleave the two and break both runs.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws JsonException
     */
    public function testGenerateReadsTheCreditNoteSeries(): void
    {
        $year = new DateTimeImmutable()->format('Y');

        $autoIncrementGenerator = $this->createMock(IdGeneratorInterface::class);

        $autoIncrementGenerator->expects(self::once())
            ->method('generate')
            ->with(
                self::isInstanceOf(CreditNote::class),
                self::callback(static fn (array $options): bool => 'AV-' === $options['prefix']
                    && '-' . $year === $options['suffix']),
            )
            ->willReturn('7');

        $systemConfig = $this->createStub(SystemConfig::class);

        $systemConfig->method('get')
            ->willReturnMap([
                ['credit_note/id_generation/strategy', null, 'auto_increment'],
                ['credit_note/id_generation/id_prefix', null, 'AV-'],
                ['credit_note/id_generation/id_suffix', null, '-{year}'],
            ]);

        $generator = new BillingIdGenerator(
            new ServiceLocator(['auto_increment' => static fn () => $autoIncrementGenerator]),
            $systemConfig,
        );

        self::assertSame('AV-7-' . $year, $generator->generate(new CreditNote()));
    }
}
