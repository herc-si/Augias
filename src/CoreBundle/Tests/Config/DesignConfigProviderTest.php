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

namespace Augias\CoreBundle\Tests\Config;

use Augias\CoreBundle\Config\DesignConfigProvider;
use Augias\CoreBundle\Form\Type\InvoiceTemplateType;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\DTO\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The design settings every install offers — the template choice among them,
 * no longer a hosted-only setting.
 */
#[CoversClass(DesignConfigProvider::class)]
final class DesignConfigProviderTest extends TestCase
{
    public function testTheTemplateChoiceIsOfferedAndGatedOnlyByTheHostedFeature(): void
    {
        $template = $this->config(BillingTemplateResolver::TEMPLATE_SETTING_KEY);

        self::assertSame(BillingTemplateRegistry::DEFAULT_SLUG, $template->value);
        self::assertSame(InvoiceTemplateType::class, $template->formType);
        // The same key the hosted plans gate on; a no-op self-hosted.
        self::assertSame(Feature::CustomTemplates->value, $template->formOptions['feature_gated'] ?? null);
        self::assertArrayNotHasKey('trial_restricted', $template->formOptions, 'Trial users may try the templates.');
    }

    public function testTheBrandingSettingsStartEmpty(): void
    {
        foreach ([DesignConfigProvider::ACCENT_COLOR, DesignConfigProvider::FOOTER_TEXT, DesignConfigProvider::IBAN, DesignConfigProvider::BIC] as $key) {
            self::assertNull($this->config($key)->value, $key . ' leaves the documents as they were until set.');
        }
    }

    private function config(string $key): Config
    {
        foreach (new DesignConfigProvider()->provide([]) as $config) {
            if ($config->key === $key) {
                return $config;
            }
        }

        self::fail($key . ' is not provided');
    }
}
