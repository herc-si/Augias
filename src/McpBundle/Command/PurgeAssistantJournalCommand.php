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

namespace Augias\McpBundle\Command;

use Augias\McpBundle\Assistant\AssistantJournalRetention;
use Augias\McpBundle\Repository\AssistantEventRepository;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use function sprintf;

/**
 * Forgets what the assistant did longer ago than the retention period.
 */
#[AsCommand(
    name: 'augias:mcp:purge-assistant-journal',
    description: 'Delete assistant journal entries past the retention period',
)]
#[AsCronTask('#daily', schedule: 'purge_assistant_journal')]
final class PurgeAssistantJournalCommand extends Command
{
    public function __construct(
        private readonly AssistantEventRepository $repository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', AssistantJournalRetention::DAYS));

        $this->io->success(sprintf(
            'Deleted %d assistant journal entr(ies) older than %s.',
            $this->repository->purgeOlderThan($cutoff),
            $cutoff->format('Y-m-d'),
        ));

        return self::SUCCESS;
    }
}
