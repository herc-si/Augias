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

namespace Augias\CoreBundle\Generator;

use Augias\CoreBundle\Generator\BillingIdGenerator\IdGeneratorInterface;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use function str_replace;

/**
 * @see \Augias\CoreBundle\Tests\Generator\BillingIdGeneratorTest
 */
final readonly class BillingIdGenerator
{
    /**
     * Placeholders a prefix or a suffix may carry, resolved every time an id is
     * generated.
     *
     * A year has to be written this way rather than typed into the setting:
     * a literal `-2026` would still be `-2026` next January, and every document
     * of the new year would carry the old one. The convention matches the
     * `{id}` placeholder the invoice email subject already uses.
     */
    private const string YEAR_PLACEHOLDER = '{year}';

    /**
     * @param ServiceLocator<IdGeneratorInterface> $generators
     */
    public function __construct(
        #[AutowireLocator(IdGeneratorInterface::class)]
        private ServiceLocator $generators,
        private SystemConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ContainerExceptionInterface
     */
    public function generate(object $entity, array $options = [], ?string $strategy = null): string
    {
        $settingSection = match (true) {
            // Before Invoice: a credit note is not one, but keeping the two
            // apart is the point — they are numbered in separate series.
            $entity instanceof CreditNote => 'credit_note',
            $entity instanceof Invoice => 'invoice',
            $entity instanceof Quote => 'quote',
            default => throw new InvalidArgumentException('Invalid entity type'),
        };

        $strategy = $strategy ?: $this->config->get($settingSection . '/id_generation/strategy');

        // Resolved before anything else looks at them: the auto-increment
        // strategy finds the previous number by cutting the prefix and suffix
        // off by length, and it has to be given the lengths that were actually
        // written onto the documents.
        $prefix = $this->resolve($this->config->get($settingSection . '/id_generation/id_prefix') ?? '');
        $suffix = $this->resolve($this->config->get($settingSection . '/id_generation/id_suffix') ?? '');

        // Pass prefix and suffix to the generator so it can handle them if needed
        $options['prefix'] = $prefix;
        $options['suffix'] = $suffix;

        $invoiceId = $this->generators->get($strategy ?? 'auto_increment')
            ->generate($entity, $options);

        return sprintf(
            '%s%s%s',
            $prefix,
            $invoiceId,
            $suffix
        );
    }

    /**
     * The year is the current one rather than the document's own date: the id
     * is handed to the form before the user has picked a date, so there is no
     * document date to read at that point.
     */
    private function resolve(string $affix): string
    {
        return str_replace(self::YEAR_PLACEHOLDER, new DateTimeImmutable()->format('Y'), $affix);
    }
}
