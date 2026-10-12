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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicReportManager;
use Augias\ElectronicInvoicingBundle\Manager\ReportDataBuilder;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicReporterInterface;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicReportRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Symfony\Component\Uid\Ulid;
use Twig\Attribute\AsTwigFunction;
use function in_array;

/**
 * Where a document to a private individual stands in e-reporting, for its
 * page: reported, failed, or waiting for the next run.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Twig\EReportingExtensionTest
 */
final readonly class EReportingExtension
{
    public function __construct(
        private ReportDataBuilder $builder,
        private ElectronicInvoiceProviderSettingRepository $settings,
        private ElectronicInvoiceProviderRegistry $providers,
        private ElectronicReportRepository $reports,
    ) {
    }

    /**
     * Null when the document is not e-reported: a business client, a draft,
     * or no platform that takes e-reporting. Otherwise the record of its
     * filing, which is null until the hourly run has filed it.
     *
     * @return array{report: ?ElectronicReport}|null
     */
    #[AsTwigFunction(name: 'ereporting_state')]
    public function state(Invoice | CreditNote $document): ?array
    {
        $id = $document->getId();

        if (! $id instanceof Ulid || ! $this->builder->isPrivateIndividual($document->getClient()) || ! $this->isIssued($document)) {
            return null;
        }

        $setting = $this->settings->findActiveForCompany($document->getCompany()->getId());

        if (! $setting instanceof ElectronicInvoiceProviderSetting || ! $this->providers->get($setting->getProvider()) instanceof ElectronicReporterInterface) {
            return null;
        }

        return [
            'report' => $this->reports->findForSource($document->getCompany()->getId(), $document instanceof CreditNote ? ReportKind::CreditNote : ReportKind::Transaction, $id),
        ];
    }

    private function isIssued(Invoice | CreditNote $document): bool
    {
        return $document instanceof CreditNote
            ? $document->isIssued()
            : in_array($document->getStatus(), ElectronicReportManager::ISSUED, true);
    }
}
