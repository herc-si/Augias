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

namespace Augias\CronBundle\Messenger;

use Augias\CoreBundle\Watch\ErrorJournal;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Throwable;
use function max;

/**
 * Writes down each run of a scheduled task (one #[AsCronTask] schedule) in
 * scheduled_task_runs: when it started, how it ended, when it is due next.
 *
 * The operator console reads it to tell a task that failed, and one that
 * stopped running at all: a scheduler that no longer consumes leaves no
 * error behind, only a "next run" that slips into the past.
 *
 * Only messages the scheduler hands to a worker are concerned (a scheduled
 * stamp and a received stamp); every other message passes straight through.
 *
 * @see \Augias\CronBundle\Tests\Messenger\ScheduledTaskJournalMiddlewareTest
 */
final readonly class ScheduledTaskJournalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ErrorJournal $journal,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $scheduled = $envelope->last(ScheduledStamp::class);

        if (! $scheduled instanceof ScheduledStamp || ! $envelope->last(ReceivedStamp::class) instanceof ReceivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $task = $scheduled->messageContext->name;
        $next = $scheduled->messageContext->nextTriggerAt;
        $started = $this->clock->now();
        $this->journal->taskStarted($task, $started);

        try {
            $result = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $e) {
            $this->journal->taskFinished($task, $e::class . ': ' . $e->getMessage(), $this->elapsed($started), $next, $this->clock->now());

            throw $e;
        }

        $this->journal->taskFinished($task, null, $this->elapsed($started), $next, $this->clock->now());

        return $result;
    }

    private function elapsed(\DateTimeImmutable $started): int
    {
        return max(0, (int) round(((float) $this->clock->now()->format('U.u') - (float) $started->format('U.u')) * 1000));
    }
}
