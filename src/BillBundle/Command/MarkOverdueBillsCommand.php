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

namespace Augias\BillBundle\Command;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Model\Graph;
use Augias\BillBundle\Repository\BillRepository;
use Augias\CoreBundle\Company\CompanySelector;
use Doctrine\ORM\EntityManagerInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Workflow\WorkflowInterface;
use function sprintf;

/**
 * Marks the supplier invoices left unpaid past their due date — late the day
 * after, as sales invoices are. Only the status changes: the dashboard's
 * purchases card counts them, and nobody is written to, the debt being the
 * company's own.
 *
 * Each bill is changed inside its own company, as any listener on the
 * workflow expects.
 *
 * @see \Augias\BillBundle\Tests\Command\MarkOverdueBillsCommandTest
 */
#[AsCommand(
    name: 'augias:bills:mark-overdue',
    description: 'Mark pending supplier invoices as overdue when past their due date',
)]
#[AsCronTask('#hourly', schedule: 'mark_bills_overdue')]
final class MarkOverdueBillsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BillRepository $bills,
        private readonly CompanySelector $companySelector,
        private readonly WorkflowInterface $billStateMachine,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $due = [];
        $filters = $this->entityManager->getFilters();
        $companyFilter = $filters->isEnabled('company');

        if ($companyFilter) {
            $filters->disable('company');
        }

        try {
            foreach ($this->bills->getPendingOverdueBills() as $bill) {
                $due[] = [$bill->getId(), $bill->getCompany()->getId()];
            }
        } finally {
            if ($companyFilter) {
                $filters->enable('company');
            }
        }

        $this->entityManager->clear();
        $marked = 0;

        foreach ($due as [$billId, $companyId]) {
            $this->companySelector->switchCompany($companyId);

            try {
                $bill = $this->bills->find($billId);

                if (! $bill instanceof Bill || BillStatus::Pending !== $bill->getStatus() || ! $this->billStateMachine->can($bill, Graph::TRANSITION_OVERDUE)) {
                    continue;
                }

                $this->billStateMachine->apply($bill, Graph::TRANSITION_OVERDUE);
                $this->entityManager->flush();
                ++$marked;
            } finally {
                $this->entityManager->clear();
                $this->companySelector->reset();
            }
        }

        $this->io->success(sprintf('Marked %d supplier invoice(s) overdue.', $marked));

        return self::SUCCESS;
    }
}
