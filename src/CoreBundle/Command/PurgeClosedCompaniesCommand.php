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

namespace Augias\CoreBundle\Command;

use Augias\CoreBundle\Company\CompanyClosure;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function count;
use function implode;
use function sprintf;

/**
 * Reminds the companies about to close, then deletes those whose closure
 * date has passed.
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyClosureTest
 */
#[AsCommand(
    name: 'augias:core:purge-closed-companies',
    description: 'Delete the companies whose closure date has passed',
)]
#[AsCronTask('#daily', schedule: 'purge_closed_companies')]
final class PurgeClosedCompaniesCommand extends Command
{
    public function __construct(
        private readonly CompanyClosure $closure,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $reminded = $this->closure->remindDue();
        $deleted = $this->closure->purgeDue();

        if ($reminded > 0) {
            $this->io->note(sprintf('Reminded %d compan(ies) closing within %d days.', $reminded, CompanyClosure::REMINDER_DAYS));
        }

        $this->io->success(0 === count($deleted)
            ? 'No company was due to close.'
            : sprintf('Deleted %d closed compan(ies): %s.', count($deleted), implode(', ', $deleted)));

        return self::SUCCESS;
    }
}
