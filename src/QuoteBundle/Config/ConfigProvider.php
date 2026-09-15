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

namespace Augias\QuoteBundle\Config;

use Augias\CoreBundle\Form\Type\BillingIdConfigurationType;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class ConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config('quote/watermark', '1', 'quote.settings.watermark.description', CheckboxType::class),
            new Config('quote/bcc_address', null, 'quote.settings.bcc_address.description', EmailType::class),
            new Config('quote/email_subject', 'New Quotation - #{id}', 'quote.settings.email_subject.description', TextType::class),
            new Config('quote/id_generation/strategy', 'auto_increment', '', BillingIdConfigurationType::class),
            new Config('quote/id_generation/id_prefix', '', 'quote.settings.id_generation.id_prefix.description', TextType::class),
            new Config('quote/id_generation/id_suffix', '', 'quote.settings.id_generation.id_suffix.description', TextType::class),
        ];
    }
}
