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

namespace Augias\CoreBundle\Config;

use Augias\CoreBundle\Form\Type\InvoiceTemplateType;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * How a company's documents look: the design template, its accent colour,
 * and the free text every page carries at its foot. The bank details are the
 * company's own, under Settings › Company (CompanyBankDetails).
 *
 * Offered on every install. The template choice is a paid option on the
 * hosted service (`feature_gated`, a no-op elsewhere); self-hosted, it is
 * simply there.
 *
 * @see \Augias\CoreBundle\Tests\Functional\DocumentBrandingTest
 */
// Seeded last: settings are listed in creation order, so this keeps the
// design tab after the company's own and the default tab on "system".
#[AsTaggedItem(priority: -100)]
final class DesignConfigProvider implements ProviderInterface
{
    public const string ACCENT_COLOR = 'design/accent_color';

    public const string FOOTER_TEXT = 'design/footer_text';

    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config(
                BillingTemplateResolver::TEMPLATE_SETTING_KEY,
                BillingTemplateRegistry::DEFAULT_SLUG,
                'settings.page.design.template.description',
                InvoiceTemplateType::class,
                ['feature_gated' => 'custom_templates'],
            ),
            new Config(self::ACCENT_COLOR, null, 'settings.page.design.accent_color.description', TextType::class, [
                'label' => 'settings.page.design.accent_color.label',
                'attr' => ['placeholder' => '#1e4976', 'maxlength' => 7],
            ]),
            new Config(self::FOOTER_TEXT, null, 'settings.page.design.footer_text.description', TextareaType::class, [
                'label' => 'settings.page.design.footer_text.label',
                'attr' => ['rows' => 3, 'maxlength' => 400],
            ]),
        ];
    }
}
