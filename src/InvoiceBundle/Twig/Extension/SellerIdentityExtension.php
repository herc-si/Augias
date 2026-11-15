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

namespace Augias\InvoiceBundle\Twig\Extension;

use const JSON_THROW_ON_ERROR;
use Augias\CoreBundle\Company\CompanySelectorInterface;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use JsonException;
use Symfony\Component\Uid\Ulid;
use Twig\Attribute\AsTwigFunction;
use function is_array;
use function is_string;
use function json_decode;
use function trim;

/**
 * What a document would be missing about the company that issues it: its
 * address, and a registration or tax identifier (SIRET, VAT number…).
 *
 * Said on the invoice and quote forms, before the document goes out, rather
 * than found on the PDF afterwards. It warns and does not block: which
 * identifier the law asks for depends on the country, and an issued invoice
 * cannot be taken back to add one.
 *
 * @see \Augias\InvoiceBundle\Tests\Twig\Extension\SellerIdentityExtensionTest
 */
final readonly class SellerIdentityExtension
{
    public const string ADDRESS = 'address';

    public const string IDENTIFIER = 'identifier';

    public function __construct(
        private SystemConfig $systemConfig,
        private TaxIdentifierRepository $taxIdentifierRepository,
        private CompanySelectorInterface $companySelector,
    ) {
    }

    /**
     * @return list<self::ADDRESS|self::IDENTIFIER>
     */
    #[AsTwigFunction('seller_identity_gaps')]
    public function gaps(): array
    {
        $gaps = [];

        if (! $this->hasAddress()) {
            $gaps[] = self::ADDRESS;
        }

        if (! $this->hasIdentifier()) {
            $gaps[] = self::IDENTIFIER;
        }

        return $gaps;
    }

    /**
     * A street or a city is enough to call it an address: a country alone,
     * which the settings form pre-selects, is not.
     */
    private function hasAddress(): bool
    {
        $raw = $this->systemConfig->get('system/company/contact_details/address');

        if (! is_string($raw) || '' === trim($raw)) {
            return false;
        }

        try {
            $address = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($address)) {
            return false;
        }

        foreach (['street1', 'city'] as $part) {
            if (is_string($address[$part] ?? null) && '' !== trim($address[$part])) {
                return true;
            }
        }

        return false;
    }

    private function hasIdentifier(): bool
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return false;
        }

        foreach ($this->taxIdentifierRepository->findCompanyIdentifiers($companyId) as $identifier) {
            if ('' !== trim((string) $identifier->getValue())) {
                return true;
            }
        }

        return false;
    }
}
