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

namespace Augias\SettingsBundle;

use Augias\CoreBundle\Entity\Company;
use Augias\SettingsBundle\Repository\SettingsRepository;
use Money\Currency;
use RuntimeException;
use Throwable;
use function trim;

/**
 * @see \Augias\SettingsBundle\Tests\SystemConfigTest
 */
class SystemConfig
{
    final public const string CURRENCY_CONFIG_PATH = 'system/company/currency';

    final public const string LOCALE_CONFIG_PATH = 'system/company/locale';

    final public const string ELECTRONIC_INVOICING_CONFIG_PATH = 'system/company/electronic_invoicing_enabled';

    /**
     * Whether the company is outside the scope of VAT — franchise en base for a
     * French micro-entreprise.
     *
     * The path sits under `accounting/` because that is the tab it is edited
     * on, and AccountingBundle's config provider is what seeds it. The constant
     * lives here rather than there because the billing side asks this question
     * on every document it renders, and a foundational bundle should not have
     * to depend on a feature bundle to find out. Same split as
     * {@see self::ELECTRONIC_INVOICING_CONFIG_PATH}, which CoreBundle seeds.
     */
    final public const string VAT_EXEMPT_CONFIG_PATH = 'accounting/vat_exempt';

    final public const string VAT_EXEMPT_MENTION_CONFIG_PATH = 'accounting/vat_exempt_mention';

    /** The rhythm VAT is declared on, and the books' — read by the e-invoicing side too. */
    final public const string VAT_PERIODICITY_CONFIG_PATH = 'accounting/vat_periodicity';

    final public const string DECLARATION_PERIODICITY_CONFIG_PATH = 'accounting/declaration_periodicity';

    /** The option for VAT on debits — here for the same reason as the exemption. */
    final public const string VAT_ON_DEBITS_CONFIG_PATH = 'accounting/vat_on_debits';

    /**
     * Printed when no wording has been set. The article reference is mandatory
     * on the invoice, so it is spelled out rather than left to be remembered.
     */
    final public const string DEFAULT_VAT_EXEMPT_MENTION = 'TVA non applicable, article 293 B du CGI';

    /**
     * @var array<string, string>
     */
    private static array $settings = [];

    public function __construct(
        private readonly ?string $installed,
        private readonly SettingsRepository $repository,
    ) {
    }

    public function get(string $key, ?Company $company = null): ?string
    {
        if (null === $this->installed || '' === $this->installed) {
            return null;
        }

        return $this->repository->getSetting($key, $company)?->getValue();
    }

    /**
     * @throws Throwable
     */
    public function set(string $path, mixed $value): void
    {
        $this->repository->store([$path => $value]);
        self::$settings = [];
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $this->load();

        return self::$settings;
    }

    private function load(): void
    {
        if ([] === self::$settings) {
            try {
                $settings = $this->repository
                    ->createQueryBuilder('c')
                    ->select('c.key', 'c.value')
                    ->orderBy('c.key')
                    ->getQuery()
                    ->getArrayResult();
            } catch (Throwable) {
                return;
            }

            self::$settings = array_combine(array_column($settings, 'key'), array_column($settings, 'value'));
        }
    }

    public function remove(string $key): void
    {
        $this->repository->delete($key);
        self::$settings = [];
    }

    /**
     * A cleared checkbox is stored as the string '0', which is truthy as a
     * non-empty string — hence the explicit comparison rather than a cast.
     */
    public function isVatExempt(?Company $company = null): bool
    {
        $value = trim((string) $this->get(self::VAT_EXEMPT_CONFIG_PATH, $company));

        return '1' === $value || 'true' === $value;
    }

    /**
     * Whether the company has opted for VAT on debits. Never while it is
     * outside the scope of VAT: it charges none.
     */
    public function isVatOnDebits(?Company $company = null): bool
    {
        $value = trim((string) $this->get(self::VAT_ON_DEBITS_CONFIG_PATH, $company));

        return ! $this->isVatExempt($company) && ('1' === $value || 'true' === $value);
    }

    public function vatExemptMention(?Company $company = null): string
    {
        $mention = trim((string) $this->get(self::VAT_EXEMPT_MENTION_CONFIG_PATH, $company));

        return '' === $mention ? self::DEFAULT_VAT_EXEMPT_MENTION : $mention;
    }

    public function getCurrency(): Currency
    {
        $currency = $this->get(self::CURRENCY_CONFIG_PATH);

        if (null === $currency || '' === $currency) {
            throw new RuntimeException('No currency set');
        }

        return new Currency($currency);
    }
}
