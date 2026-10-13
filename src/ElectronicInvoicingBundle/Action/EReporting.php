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

namespace Augias\ElectronicInvoicingBundle\Action;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ReportedDocuments;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicDeclarationsInterface;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicReporterInterface;
use Augias\ElectronicInvoicingBundle\Provider\ReportPreview;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicDeclarationRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicReportRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where e-reporting stands: what the next declaration will carry, what the
 * platform sent the tax administration and how it was received, and what
 * Augias filed with the platform, document by document.
 */
final readonly class EReporting
{
    public function __construct(
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private ElectronicInvoiceProviderSettingRepository $settings,
        private ElectronicInvoiceProviderRegistry $providers,
        private ElectronicDeclarationRepository $declarations,
        private ElectronicReportRepository $reports,
        private ReportedDocuments $documents,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@AugiasElectronicInvoicing/EReporting/index.html.twig')]
    public function __invoke(): array
    {
        $companyId = $this->companySelector->getCompany();
        $company = null === $companyId ? null : $this->companyRepository->find($companyId);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        $setting = $this->settings->findActiveForCompany($company->getId());
        $provider = $setting instanceof ElectronicInvoiceProviderSetting ? $this->providers->get($setting->getProvider()) : null;

        [$previews, $previewError] = $provider instanceof ElectronicDeclarationsInterface && $setting instanceof ElectronicInvoiceProviderSetting
            ? $this->previews($provider, $setting->getSettings())
            : [[], null];

        $reports = $this->reports->latestForCompany($company->getId());

        return [
            'setting' => $setting,
            'reporting' => $provider instanceof ElectronicReporterInterface,
            'showsDeclarations' => $provider instanceof ElectronicDeclarationsInterface,
            'previews' => $previews,
            'previewError' => $previewError,
            'declarations' => $this->declarations->forCompany($company->getId()),
            'reports' => array_map(fn ($report): array => [
                'report' => $report,
                'invoice' => $this->documents->invoiceFor($report),
                'creditNote' => $this->documents->creditNoteFor($report),
                'bill' => $this->documents->billFor($report),
            ], $reports),
        ];
    }

    /**
     * Asked of the platform each time: it changes with every sale.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: list<ReportPreview>, 1: ?string}
     */
    private function previews(ElectronicDeclarationsInterface $provider, array $config): array
    {
        $today = DateTimeImmutable::createFromInterface($this->clock->now());
        $previews = [];

        try {
            foreach (['transaction', 'payment'] as $kind) {
                $preview = $provider->preview($config, $kind, $today);

                if ($preview instanceof ReportPreview) {
                    $previews[] = $preview;
                }
            }
        } catch (RuntimeException $e) {
            return [$previews, $e->getMessage()];
        }

        return [$previews, null];
    }
}
