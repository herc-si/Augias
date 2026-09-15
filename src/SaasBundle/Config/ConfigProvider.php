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

namespace Augias\SaasBundle\Config;

use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\SaasBundle\Feature\Feature;
use Augias\SaasBundle\Form\Type\CustomDomainType;
use Augias\SaasBundle\Form\Type\InvoiceTemplateType;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

/**
 * @see \Augias\SaasBundle\Tests\Config\ConfigProviderTest
 */
final class ConfigProvider implements ProviderInterface
{
    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config(
                'system/general/hide_powered_by',
                '0',
                'saas.settings.hide_powered_by.description',
                CheckboxType::class,
                ['feature_gated' => Feature::CustomBranding->value]
            ),
            new Config(
                'system/domain/custom_domain',
                null,
                'saas.settings.custom_domain.description',
                CustomDomainType::class,
                [
                    'feature_gated' => Feature::CustomDomain->value,
                    'trial_restricted' => true,
                ],
            ),
            new Config(
                BillingTemplateResolver::TEMPLATE_SETTING_KEY,
                BillingTemplateRegistry::DEFAULT_SLUG,
                'saas.settings.billing_template.description',
                InvoiceTemplateType::class,
                ['feature_gated' => Feature::CustomTemplates->value],
            ),
        ];
    }
}
