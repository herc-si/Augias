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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Manager\SuperPdpStatusRefresher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function assert;
use function count;
use function sprintf;

/**
 * Refreshes, every hour and for every company, where the invoices sent to
 * SUPER PDP stand — see {@see SuperPdpStatusRefresher}.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Command\PollSuperPdpInvoiceStatusCommandTest
 */
#[AsCommand(
    name: 'augias:einvoicing:poll-super-pdp-status',
    description: 'Refresh the processing status of invoices submitted to SUPER PDP',
)]
#[AsCronTask('#hourly', schedule: 'poll_super_pdp_invoice_status')]
final class PollSuperPdpInvoiceStatusCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly SuperPdpStatusRefresher $refresher,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $entityManager = $this->registry->getManagerForClass(ElectronicInvoiceSubmission::class);
        assert($entityManager instanceof EntityManagerInterface);

        // Every company: run from cron there is no current one.
        $filters = $entityManager->getFilters();
        $companyFilterEnabled = $filters->isEnabled('company');

        if ($companyFilterEnabled) {
            $filters->disable('company');
        }

        try {
            $result = $this->refresher->refreshPending();
        } finally {
            if ($companyFilterEnabled) {
                $filters->enable('company');
            }
        }

        foreach ($result['errors'] as $error) {
            $this->io->error($error);
        }

        $this->io->success(sprintf('Refreshed %d submission(s). Errors: %d', $result['updated'], count($result['errors'])));

        return self::SUCCESS;
    }
}
