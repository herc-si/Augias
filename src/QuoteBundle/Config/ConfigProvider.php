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

use Augias\CoreBundle\Billing\TermsDocument;
use Augias\CoreBundle\Form\Type\BillingIdConfigurationType;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConfigProvider implements ProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return Config[]
     */
    public function provide(array $data): array
    {
        return [
            new Config('quote/watermark', '1', 'quote.settings.watermark.description', CheckboxType::class),
            new Config('quote/bcc_address', null, 'quote.settings.bcc_address.description', EmailType::class),
            new Config('quote/email_subject', null, 'quote.settings.email_subject.description', TextType::class),
            new Config('quote/id_generation/strategy', 'auto_increment', '', BillingIdConfigurationType::class),
            new Config('quote/id_generation/id_prefix', '', 'quote.settings.id_generation.id_prefix.description', TextType::class),
            new Config('quote/id_generation/id_suffix', '', 'quote.settings.id_generation.id_suffix.description', TextType::class),
            // What a new quote opens with, worded for a business or for a private
            // individual: suggested in the company's language, the company's to change.
            ...$this->defaultTerms(TermsDocument::Quote, $data['locale'] ?? null),
        ];
    }

    /**
     * @return list<Config>
     */
    private function defaultTerms(TermsDocument $document, ?string $locale): array
    {
        $configs = [];

        foreach ([true, false] as $business) {
            $configs[] = new Config(
                $document->settingKey($business),
                $this->translator->trans($document->suggestionKey($business), [], null, $locale),
                null,
                TextareaType::class,
                ['attr' => ['rows' => 5]],
            );
        }

        return $configs;
    }
}
