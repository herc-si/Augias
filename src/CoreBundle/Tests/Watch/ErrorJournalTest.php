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

namespace Augias\CoreBundle\Tests\Watch;

use Augias\CoreBundle\Watch\ErrorJournal;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorJournal::class)]
final class ErrorJournalTest extends TestCase
{
    use JournalDatabase;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->journalDatabase();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->dropJournalDatabase();
    }

    public function testTheSameErrorIsOneLineThatCountsItsOccurrences(): void
    {
        $journal = new ErrorJournal($this->db);

        $journal->recordError('abc', 'ERROR', 'web', 'RuntimeException', 'first', 'src/A.php', 12, new DateTimeImmutable('2026-11-17 10:00:00'));
        $journal->recordError('abc', 'CRITICAL', 'web', 'RuntimeException', 'second', 'src/A.php', 12, new DateTimeImmutable('2026-11-17 11:00:00'));
        $journal->recordError('def', 'ERROR', 'worker', null, 'another one', null, null, new DateTimeImmutable('2026-11-17 12:00:00'));

        $rows = $this->db->fetchAllAssociative('SELECT fingerprint, level, source, message, occurrences, first_seen_at, last_seen_at FROM error_records ORDER BY fingerprint');

        self::assertCount(2, $rows);
        self::assertSame('abc', $rows[0]['fingerprint']);
        self::assertSame(2, (int) $rows[0]['occurrences']);
        self::assertSame('CRITICAL', $rows[0]['level'], 'the latest occurrence speaks for the line');
        self::assertSame('second', $rows[0]['message']);
        self::assertSame('2026-11-17 10:00:00', $rows[0]['first_seen_at']);
        self::assertSame('2026-11-17 11:00:00', $rows[0]['last_seen_at']);
        self::assertSame('worker', $rows[1]['source']);
    }

    public function testATaskRunIsWrittenDownFromItsStartToItsEnd(): void
    {
        $journal = new ErrorJournal($this->db);

        $journal->taskStarted('send_recurring_invoices', new DateTimeImmutable('2026-11-17 10:00:00'));
        self::assertSame('running', $this->db->fetchOne("SELECT status FROM scheduled_task_runs WHERE task = 'send_recurring_invoices'"));

        $journal->taskFinished('send_recurring_invoices', null, 1234, new DateTimeImmutable('2026-11-17 11:00:00'), new DateTimeImmutable('2026-11-17 10:00:01'));
        $journal->taskStarted('send_recurring_invoices', new DateTimeImmutable('2026-11-17 11:00:00'));
        $journal->taskFinished('send_recurring_invoices', 'RuntimeException: SMTP down', 50, new DateTimeImmutable('2026-11-17 12:00:00'), new DateTimeImmutable('2026-11-17 11:00:01'));

        $row = $this->db->fetchAssociative("SELECT * FROM scheduled_task_runs WHERE task = 'send_recurring_invoices'");

        self::assertIsArray($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('RuntimeException: SMTP down', $row['last_error']);
        self::assertSame(2, (int) $row['runs']);
        self::assertSame(50, (int) $row['duration_ms']);
        self::assertSame('2026-11-17 12:00:00', $row['next_expected_at']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM scheduled_task_runs'));
    }

    public function testAJournalThatCannotWriteFailsSilently(): void
    {
        // No tables at all: every write fails, none of them throws.
        $empty = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $journal = new ErrorJournal($empty);

        $journal->recordError('abc', 'ERROR', 'web', null, 'message', null, null, new DateTimeImmutable());
        $journal->taskStarted('task', new DateTimeImmutable());
        $journal->taskFinished('task', null, 1, null, new DateTimeImmutable());

        $this->expectNotToPerformAssertions();
    }
}
