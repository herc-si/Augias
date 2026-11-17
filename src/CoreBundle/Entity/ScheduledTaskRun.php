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

namespace Augias\CoreBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The last run of one scheduled task (one #[AsCronTask] schedule): when it
 * started and ended, whether it failed, and when it is due next. One row per
 * task, rewritten at each run by ScheduledTaskJournalMiddleware.
 *
 * What the operator console reads to tell a task that failed, or that
 * stopped running at all, which nothing else would show: a scheduler that is
 * not consuming leaves no error behind.
 */
#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Entity]
class ScheduledTaskRun
{
    final public const string TABLE_NAME = 'scheduled_task_runs';

    final public const string RUNNING = 'running';

    final public const string OK = 'ok';

    final public const string FAILED = 'failed';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    /**
     * The schedule's name, as #[AsCronTask(schedule: …)] gives it.
     */
    #[ORM\Column(name: 'task', type: Types::STRING, length: 100, unique: true)]
    private string $task = '';

    #[ORM\Column(name: 'status', type: Types::STRING, length: 16)]
    private string $status = self::RUNNING;

    #[ORM\Column(name: 'last_started_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastStartedAt;

    #[ORM\Column(name: 'last_finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastFinishedAt = null;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /**
     * When the scheduler means to run it next: past that, plus some slack, a
     * task that has not started again has stopped running.
     */
    #[ORM\Column(name: 'next_expected_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextExpectedAt = null;

    #[ORM\Column(name: 'runs', type: Types::INTEGER)]
    private int $runs = 0;

    private function __construct()
    {
        $this->id = new Ulid();
        $this->lastStartedAt = new DateTimeImmutable();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLastStartedAt(): DateTimeImmutable
    {
        return $this->lastStartedAt;
    }

    public function getLastFinishedAt(): ?DateTimeImmutable
    {
        return $this->lastFinishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getNextExpectedAt(): ?DateTimeImmutable
    {
        return $this->nextExpectedAt;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }
}
