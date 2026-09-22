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

namespace Augias\UserBundle\Command;

use Augias\UserBundle\LoginRecordRetention;
use Augias\UserBundle\Repository\LoginRecordRepository;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function sprintf;

/**
 * Forgets sign-ins older than the retention period.
 *
 * Without this the journal is not a security tool but a permanent record of
 * where someone was and on what device, which is a different thing and one
 * nobody asked for. The page says how far back it goes; this is what makes
 * that true.
 *
 * @see \Augias\UserBundle\Tests\Command\PurgeLoginRecordsCommandTest
 */
#[AsCommand(
    name: 'augias:users:purge-sign-ins',
    description: 'Delete sign-in records past the retention period',
)]
#[AsCronTask('#daily', schedule: 'purge_login_records')]
final class PurgeLoginRecordsCommand extends Command
{
    public function __construct(
        private readonly LoginRecordRepository $repository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', LoginRecordRetention::DAYS));

        $deleted = $this->repository->purgeOlderThan($cutoff);

        $this->io->success(sprintf(
            'Deleted %d sign-in record(s) older than %s.',
            $deleted,
            $cutoff->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
