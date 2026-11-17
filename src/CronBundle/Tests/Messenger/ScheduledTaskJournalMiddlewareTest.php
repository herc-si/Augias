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

namespace Augias\CronBundle\Tests\Messenger;

use Augias\CoreBundle\Tests\Watch\JournalDatabase;
use Augias\CoreBundle\Watch\ErrorJournal;
use Augias\CronBundle\Messenger\ScheduledTaskJournalMiddleware;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

#[CoversClass(ScheduledTaskJournalMiddleware::class)]
final class ScheduledTaskJournalMiddlewareTest extends TestCase
{
    use JournalDatabase;

    private Connection $db;

    private ScheduledTaskJournalMiddleware $middleware;

    protected function setUp(): void
    {
        $this->db = $this->journalDatabase();
        $this->middleware = new ScheduledTaskJournalMiddleware(new ErrorJournal($this->db), new MockClock('2026-11-17 10:00:00'));
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->dropJournalDatabase();
    }

    public function testARunThatSucceedsIsWrittenDownWithItsNextDueDate(): void
    {
        $this->middleware->handle($this->scheduled('send_recurring_invoices'), $this->stack());

        $row = $this->db->fetchAssociative('SELECT * FROM scheduled_task_runs');

        self::assertIsArray($row);
        self::assertSame('send_recurring_invoices', $row['task']);
        self::assertSame('ok', $row['status']);
        self::assertNull($row['last_error']);
        self::assertSame('2026-11-17 11:00:00', $row['next_expected_at']);
        self::assertSame(1, (int) $row['runs']);
    }

    public function testARunThatFailsIsWrittenDownAndTheFailureStillPropagates(): void
    {
        try {
            $this->middleware->handle($this->scheduled('mark_invoices_overdue'), $this->stack(new RuntimeException('database gone')));
            self::fail('The failure must reach Messenger');
        } catch (RuntimeException) {
        }

        $row = $this->db->fetchAssociative('SELECT status, last_error FROM scheduled_task_runs');

        self::assertSame(['status' => 'failed', 'last_error' => 'RuntimeException: database gone'], $row);
    }

    public function testOtherMessagesPassStraightThrough(): void
    {
        // Dispatched, not yet received: the scheduler queued it, no worker ran it.
        $this->middleware->handle(new Envelope(new stdClass(), [$this->stamp('send_recurring_invoices')]), $this->stack());
        // Received, but not a scheduled task.
        $this->middleware->handle(new Envelope(new stdClass(), [new ReceivedStamp('async')]), $this->stack());

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM scheduled_task_runs'));
    }

    private function scheduled(string $task): Envelope
    {
        return new Envelope(new stdClass(), [$this->stamp($task), new ReceivedStamp('scheduler_' . $task)]);
    }

    private function stamp(string $task): ScheduledStamp
    {
        return new ScheduledStamp(new MessageContext(
            $task,
            'id',
            CronExpressionTrigger::fromSpec('0 * * * *'),
            new DateTimeImmutable('2026-11-17 10:00:00'),
            new DateTimeImmutable('2026-11-17 11:00:00'),
        ));
    }

    private function stack(?RuntimeException $failure = null): StackInterface
    {
        return new StackMiddleware(new class($failure) implements MiddlewareInterface {
            public function __construct(
                private readonly ?RuntimeException $failure,
            ) {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                if (null !== $this->failure) {
                    throw $this->failure;
                }

                return $envelope;
            }
        });
    }
}
