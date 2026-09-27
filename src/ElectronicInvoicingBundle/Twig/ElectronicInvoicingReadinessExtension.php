<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Twig;

use Augias\CoreBundle\Company\CompanySelectorInterface;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Symfony\Component\Uid\Ulid;
use Twig\Attribute\AsTwigFunction;
use function trim;

/**
 * What still stands between a company and electronic invoicing, shown next
 * to the switch so it is not discovered one refused invoice at a time: its
 * SIRET, its VAT number (none is asked under the VAT franchise, BT-32 names
 * the seller by SIRET) and a platform to send through.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Twig\ElectronicInvoicingReadinessExtensionTest
 */
final readonly class ElectronicInvoicingReadinessExtension
{
    public function __construct(
        private ElectronicInvoiceProviderRegistry $registry,
        private SystemConfig $systemConfig,
        private TaxIdentifierRepository $taxIdentifierRepository,
        private CompanySelectorInterface $companySelector,
    ) {
    }

    /**
     * @return array{siret: bool, vat: bool|null, platform: bool, ready: bool}
     */
    #[AsTwigFunction('einvoicing_readiness')]
    public function readiness(): array
    {
        $labels = [];
        $companyId = $this->companySelector->getCompany();

        if ($companyId instanceof Ulid) {
            foreach ($this->taxIdentifierRepository->findCompanyIdentifiers($companyId) as $identifier) {
                if ('' !== trim((string) $identifier->getValue())) {
                    $labels[$identifier->getLabel()] = true;
                }
            }
        }

        $siret = isset($labels[TaxIdentifierType::SIRET]);
        $vat = $this->systemConfig->isVatExempt() ? null : isset($labels[TaxIdentifierType::VAT_NUMBER]);
        $platform = $this->registry->hasActiveProvider();

        return [
            'siret' => $siret,
            'vat' => $vat,
            'platform' => $platform,
            'ready' => $siret && false !== $vat && $platform,
        ];
    }
}
