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

namespace Augias\SettingsBundle\Tests\Config;

use Augias\AccountingBundle\Config\AccountingConfigProvider;
use Augias\CoreBundle\Config\SystemConfigProvider;
use Augias\InvoiceBundle\Config\ConfigProvider as InvoiceConfigProvider;
use Augias\MailerBundle\Config\ConfigProvider as MailerConfigProvider;
use Augias\QuoteBundle\Config\ConfigProvider as QuoteConfigProvider;
use Augias\SaasBundle\Config\ConfigProvider as SaasConfigProvider;
use Augias\SettingsBundle\Config\ProviderInterface;
use Augias\SettingsBundle\DTO\Config;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use function sprintf;
use function str_contains;

/**
 * A setting's help text is stored in the database when a company is created and
 * handed to the form as-is, so prose written there is prose every reader gets,
 * in whatever language it was typed. Keys instead — and a key with no entry in a
 * catalogue renders as the key itself, which is worse than the English it
 * replaced, so both catalogues are checked.
 */
#[CoversNothing]
final class SettingsHelpIsTranslatableTest extends KernelTestCase
{
    /**
     * Named rather than discovered: the providers are injected as an
     * AutowireIterator, which is not something a test can ask the container
     * for. A new provider belongs in this list — and the count asserted at the
     * end is what notices if this list quietly stops matching reality.
     *
     * @return list<class-string<ProviderInterface>>
     */
    private static function providers(): array
    {
        return [
            InvoiceConfigProvider::class,
            QuoteConfigProvider::class,
            SaasConfigProvider::class,
            SystemConfigProvider::class,
            MailerConfigProvider::class,
            AccountingConfigProvider::class,
        ];
    }

    public function testEveryHelpTextIsAKeyBothCataloguesAnswer(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $described = 0;

        foreach (self::providers() as $class) {
            // The SaaS provider is not registered in the default kernel, so it
            // is built by hand rather than skipped: its settings are shown to
            // someone, and a missing translation is just as visible there.
            $provider = self::getContainer()->has($class) ? self::getContainer()->get($class) : new $class();
            self::assertInstanceOf(ProviderInterface::class, $provider);

            foreach ($provider->provide(['company_name' => 'Test', 'currency' => 'EUR', 'locale' => 'en']) as $config) {
                self::assertInstanceOf(Config::class, $config);

                if (null === $config->description || '' === $config->description) {
                    continue;
                }

                ++$described;
                $key = $config->key;

                self::assertFalse(
                    str_contains($config->description, ' '),
                    sprintf('The help for "%s" is prose rather than a translation key: "%s".', $key, $config->description),
                );

                foreach (['en', 'fr'] as $locale) {
                    self::assertNotSame(
                        $config->description,
                        $translator->trans($config->description, [], null, $locale),
                        sprintf('"%s" has no %s translation, so the settings screen would show the key itself.', $config->description, $locale),
                    );
                }
            }
        }

        // A guard on the guard: if the providers ever stop being reachable this
        // test would pass by finding nothing to check.
        self::assertGreaterThan(15, $described);
    }
}
