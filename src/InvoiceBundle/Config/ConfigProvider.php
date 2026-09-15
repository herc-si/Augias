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

namespace Augias\InvoiceBundle\Config;

use Augias\CoreBundle\Form\Type\BillingIdConfigurationType;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config('invoice/watermark', '1', 'invoice.settings.watermark.description', CheckboxType::class),
            new Config('invoice/bcc_address', null, 'invoice.settings.bcc_address.description', EmailType::class),
            new Config('invoice/email_subject', 'New Invoice - #{id}', 'invoice.settings.email_subject.description', TextType::class),
            new Config('invoice/id_generation/strategy', 'auto_increment', '', BillingIdConfigurationType::class),
            new Config('invoice/id_generation/id_prefix', 'FACT-', 'invoice.settings.id_generation.id_prefix.description', TextType::class),
            // The year is a placeholder, not a literal: written out as -2026 it
            // would still read 2026 next January. {year} is resolved every time
            // an id is generated.
            new Config('invoice/id_generation/id_suffix', '-{year}', 'invoice.settings.id_generation.id_suffix.description', TextType::class),
            // A credit note is numbered in a series of its own, and only in an
            // unbroken run: a gap in the numbering of a book document is what
            // an audit looks for first, so the random, uuid, ulid and timestamp
            // strategies are not offered here at all.
            new Config('credit_note/id_generation/strategy', 'auto_increment', '', BillingIdConfigurationType::class, ['sequential_only' => true]),
            new Config('credit_note/id_generation/id_prefix', 'AV-', 'credit_note.settings.id_generation.id_prefix.description', TextType::class),
            new Config('credit_note/id_generation/id_suffix', '-{year}', 'credit_note.settings.id_generation.id_suffix.description', TextType::class),
            new Config(
                'invoice/reminder/enabled',
                '1',
                'invoice.settings.reminder.enabled.description',
                CheckboxType::class,
                ['feature_gated' => Feature::AutomatedReminders->value],
            ),
            new Config(
                'invoice/reminder/pre_due_enabled',
                '1',
                'invoice.settings.reminder.pre_due_enabled.description',
                CheckboxType::class,
                ['feature_gated' => Feature::AutomatedReminders->value],
            ),
            new Config(
                'invoice/reminder/pre_due_days',
                '3',
                'invoice.settings.reminder.pre_due_days.description',
                IntegerType::class,
                ['attr' => ['min' => 0, 'max' => 30], 'feature_gated' => Feature::AutomatedReminders->value],
            ),
        ];
    }
}
