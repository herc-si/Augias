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
 * Deletes the companies whose closure date has passed.
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
        $deleted = $this->closure->purgeDue();

        $this->io->success(0 === count($deleted)
            ? 'No company was due to close.'
            : sprintf('Deleted %d closed compan(ies): %s.', count($deleted), implode(', ', $deleted)));

        return self::SUCCESS;
    }
}
