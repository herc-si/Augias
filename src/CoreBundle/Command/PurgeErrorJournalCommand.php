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

use Augias\CoreBundle\Entity\ErrorRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function sprintf;

/**
 * Forgets the errors that have not happened again for ninety days.
 *
 * An error message can carry a customer's name or address; the journal is a
 * diagnostic tool, kept as long as the sign-in journal and no longer.
 *
 * @see \Augias\CoreBundle\Tests\Command\PurgeErrorJournalCommandTest
 */
#[AsCommand(
    name: 'augias:errors:purge',
    description: 'Delete the errors that did not happen again for ninety days',
)]
#[AsCronTask('#daily', schedule: 'purge_error_journal')]
final class PurgeErrorJournalCommand extends Command
{
    public const int DAYS = 90;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::DAYS));

        $deleted = $this->connection->executeStatement(
            'DELETE FROM ' . ErrorRecord::TABLE_NAME . ' WHERE last_seen_at < :cutoff',
            ['cutoff' => $cutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );

        $this->io->success(sprintf('Deleted %d error record(s) last seen before %s.', $deleted, $cutoff->format('Y-m-d')));

        return self::SUCCESS;
    }
}
