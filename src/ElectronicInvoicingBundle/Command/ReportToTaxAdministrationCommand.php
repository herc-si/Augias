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

namespace Augias\ElectronicInvoicingBundle\Command;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicReportManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Throwable;
use function assert;
use function sprintf;

/**
 * Files e-reporting data — sales to private individuals and the payments
 * for them — for every company whose platform takes it.
 *
 * @see \Augias\ElectronicInvoicingBundle\Manager\ElectronicReportManager
 */
#[AsCommand(
    name: 'augias:einvoicing:report',
    description: 'Report sales to private individuals, and their payments, for e-reporting',
)]
#[AsCronTask('#hourly', schedule: 'report_to_tax_administration')]
final class ReportToTaxAdministrationCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyRepository $companyRepository,
        private readonly ElectronicReportManager $reportManager,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $entityManager = $this->registry->getManagerForClass(Company::class);
        assert($entityManager instanceof EntityManagerInterface);

        $filters = $entityManager->getFilters();
        $companyFilterEnabled = $filters->isEnabled('company');

        if ($companyFilterEnabled) {
            $filters->disable('company');
        }

        $reported = 0;
        $failed = 0;

        try {
            foreach ($this->companyRepository->findAll() as $company) {
                try {
                    $stats = $this->reportManager->reportPending($company);
                    $reported += $stats['reported'];
                    $failed += $stats['failed'];
                } catch (Throwable $e) {
                    ++$failed;
                    $this->io->error(sprintf('Could not report for company %s: %s', (string) $company->getId(), $e->getMessage()));
                }
            }
        } finally {
            if ($companyFilterEnabled) {
                $filters->enable('company');
            }
        }

        $this->io->success(sprintf('Reported: %d, failed: %d', $reported, $failed));

        return self::SUCCESS;
    }
}
