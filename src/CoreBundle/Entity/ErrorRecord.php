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
 * One kind of error this instance has logged, however many times it happened:
 * the error journal the operator console reads, so that nobody has to send
 * error reports to a third party to learn that the application broke.
 *
 * Written by ErrorJournal over a connection of its own, never through the
 * entity manager: an error often happens inside a transaction that is about to
 * be rolled back, and the record must outlive it. Mapped here so that the
 * schema knows the table, not to be loaded.
 *
 * Kept ninety days after its last occurrence (PurgeErrorJournalCommand).
 */
#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Index(name: 'error_records_last_seen', columns: ['last_seen_at'])]
#[ORM\Entity]
class ErrorRecord
{
    final public const string TABLE_NAME = 'error_records';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    /**
     * The same error, wherever and however often it happens: the exception's
     * class, file and line, or the log message's template.
     */
    #[ORM\Column(name: 'fingerprint', type: Types::STRING, length: 40, unique: true)]
    private string $fingerprint = '';

    #[ORM\Column(name: 'level', type: Types::STRING, length: 16)]
    private string $level = '';

    /**
     * Where it happened: `web`, `console` or `worker`.
     */
    #[ORM\Column(name: 'source', type: Types::STRING, length: 16)]
    private string $source = '';

    #[ORM\Column(name: 'exception_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(name: 'message', type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(name: 'file', type: Types::STRING, length: 255, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(name: 'line', type: Types::INTEGER, nullable: true)]
    private ?int $line = null;

    #[ORM\Column(name: 'occurrences', type: Types::INTEGER)]
    private int $occurrences = 0;

    #[ORM\Column(name: 'first_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    private function __construct()
    {
        $this->id = new Ulid();
        $this->firstSeenAt = $this->lastSeenAt = new DateTimeImmutable();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
