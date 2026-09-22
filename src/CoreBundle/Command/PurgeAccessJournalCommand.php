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

use Augias\CoreBundle\AccessJournalRetention;
use Augias\CoreBundle\Repository\RecordAccessRepository;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function sprintf;

/**
 * Forgets what was opened longer ago than the retention period.
 *
 * @see \Augias\CoreBundle\Tests\Command\PurgeAccessJournalCommandTest
 */
#[AsCommand(
    name: 'augias:core:purge-access-journal',
    description: 'Delete journal entries past the retention period',
)]
#[AsCronTask('#daily', schedule: 'purge_access_journal')]
final class PurgeAccessJournalCommand extends Command
{
    public function __construct(
        private readonly RecordAccessRepository $repository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', AccessJournalRetention::DAYS));

        $this->io->success(sprintf(
            'Deleted %d journal entr(ies) older than %s.',
            $this->repository->purgeOlderThan($cutoff),
            $cutoff->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
