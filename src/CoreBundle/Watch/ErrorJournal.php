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

namespace Augias\CoreBundle\Watch;

use Augias\CoreBundle\Entity\ErrorRecord;
use Augias\CoreBundle\Entity\ScheduledTaskRun;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function mb_substr;

/**
 * Writes the error journal and the scheduled tasks' last runs (error_records,
 * scheduled_task_runs), which the operator console reads.
 *
 * Over a connection of its own, opened on first use with the application's
 * parameters: an error usually happens inside a transaction that is about to
 * be rolled back, or on a connection the error itself broke, and the journal
 * must outlive both.
 *
 * Nothing here may throw. The journal is how operators learn that something
 * failed; a journal that fails must not add a failure of its own, nor turn a
 * logged error into a crash.
 */
final class ErrorJournal
{
    public const int MESSAGE_LENGTH = 2000;

    private ?Connection $own = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function recordError(
        string $fingerprint,
        string $level,
        string $source,
        ?string $exceptionClass,
        string $message,
        ?string $file,
        ?int $line,
        DateTimeImmutable $at,
    ): void {
        $this->quietly(function (Connection $db) use ($fingerprint, $level, $source, $exceptionClass, $message, $file, $line, $at): void {
            $update = fn (): int => (int) $db->executeStatement(
                'UPDATE ' . ErrorRecord::TABLE_NAME . ' SET occurrences = occurrences + 1, last_seen_at = :at, level = :level, message = :message WHERE fingerprint = :fingerprint',
                ['at' => $at, 'level' => $level, 'message' => mb_substr($message, 0, self::MESSAGE_LENGTH), 'fingerprint' => $fingerprint],
                ['at' => Types::DATETIME_IMMUTABLE],
            );

            if ($update() > 0) {
                return;
            }

            try {
                $db->insert(ErrorRecord::TABLE_NAME, [
                    'id' => new Ulid(),
                    'fingerprint' => $fingerprint,
                    'level' => $level,
                    'source' => $source,
                    'exception_class' => null === $exceptionClass ? null : mb_substr($exceptionClass, 0, 255),
                    'message' => mb_substr($message, 0, self::MESSAGE_LENGTH),
                    'file' => null === $file ? null : mb_substr($file, -255),
                    'line' => $line,
                    'occurrences' => 1,
                    'first_seen_at' => $at,
                    'last_seen_at' => $at,
                ], ['id' => UlidType::NAME, 'first_seen_at' => Types::DATETIME_IMMUTABLE, 'last_seen_at' => Types::DATETIME_IMMUTABLE]);
            } catch (UniqueConstraintViolationException) {
                // Another process recorded the same error in between.
                $update();
            }
        });
    }

    public function taskStarted(string $task, DateTimeImmutable $at): void
    {
        $this->quietly(function (Connection $db) use ($task, $at): void {
            $update = fn (): int => (int) $db->executeStatement(
                'UPDATE ' . ScheduledTaskRun::TABLE_NAME . ' SET status = :status, last_started_at = :at WHERE task = :task',
                ['status' => ScheduledTaskRun::RUNNING, 'at' => $at, 'task' => $task],
                ['at' => Types::DATETIME_IMMUTABLE],
            );

            if ($update() > 0) {
                return;
            }

            try {
                $db->insert(ScheduledTaskRun::TABLE_NAME, [
                    'id' => new Ulid(),
                    'task' => mb_substr($task, 0, 100),
                    'status' => ScheduledTaskRun::RUNNING,
                    'last_started_at' => $at,
                    'runs' => 0,
                ], ['id' => UlidType::NAME, 'last_started_at' => Types::DATETIME_IMMUTABLE]);
            } catch (UniqueConstraintViolationException) {
                $update();
            }
        });
    }

    public function taskFinished(string $task, ?string $error, int $durationMs, ?DateTimeImmutable $nextExpectedAt, DateTimeImmutable $at): void
    {
        $this->quietly(static function (Connection $db) use ($task, $error, $durationMs, $nextExpectedAt, $at): void {
            $db->executeStatement(
                'UPDATE ' . ScheduledTaskRun::TABLE_NAME . ' SET status = :status, last_finished_at = :at, duration_ms = :duration, last_error = :error, next_expected_at = :next, runs = runs + 1 WHERE task = :task',
                [
                    'status' => null === $error ? ScheduledTaskRun::OK : ScheduledTaskRun::FAILED,
                    'at' => $at,
                    'duration' => $durationMs,
                    'error' => null === $error ? null : mb_substr($error, 0, self::MESSAGE_LENGTH),
                    'next' => $nextExpectedAt,
                    'task' => $task,
                ],
                ['at' => Types::DATETIME_IMMUTABLE, 'next' => Types::DATETIME_IMMUTABLE],
            );
        });
    }

    /**
     * @param callable(Connection): void $write
     */
    private function quietly(callable $write): void
    {
        try {
            $write($this->own ??= DriverManager::getConnection($this->connection->getParams(), $this->connection->getConfiguration()));
        } catch (Throwable) {
            // See the class comment: the journal never fails louder than what it records.
            // A connection left in a bad state is dropped, the next write opens a new one.
            $this->own = null;
        }
    }
}
