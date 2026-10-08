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

namespace Augias\CoreBundle\Activity;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Journal\Journalled;
use Augias\CoreBundle\Storage\StoredDocument;
use Augias\UserBundle\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Ulid;
use function mb_substr;

/**
 * Writes a line in a document's history.
 *
 * One row, written on its own through the connection, as the journal of
 * opened records does: a flush would also write whatever else the unit of
 * work was holding at that moment, which is not this class's to decide (a
 * draft attached to a client's contacts by a form, for one, answered with a
 * 500 when it was flushed by the journal).
 */
final readonly class DocumentActivityRecorder
{
    /**
     * The same visit counted once: a reload, or the page and then its PDF
     * read within a few minutes, is one reading.
     */
    private const string REPEAT_WINDOW = '-5 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $recipients
     */
    public function record(
        Journalled $document,
        Company $company,
        DocumentActivityType $type,
        ?string $detail = null,
        array $recipients = [],
        ?string $userAgent = null,
        ?string $ipAddress = null,
        ?StoredDocument $proof = null,
    ): void {
        $recordId = $document->getId();

        if (! $recordId instanceof Ulid) {
            return;
        }

        $now = $this->clock->now();

        if ($type->canBeAutomated() && $this->entityManager->getRepository(DocumentActivity::class)->hasSince($document->journalKind(), $recordId, $type, $now->modify(self::REPEAT_WINDOW), DocumentActivity::looksAutomated($userAgent))) {
            return;
        }

        // What the client did is not done by whoever happens to be logged in:
        // the link is opened without an account.
        $user = $type->byClient() ? null : $this->security->getUser();

        $this->entityManager->getConnection()->insert(
            DocumentActivity::TABLE_NAME,
            [
                'id' => new Ulid(),
                'company_id' => $company->getId(),
                'kind' => $document->journalKind()->value,
                'record_id' => $recordId,
                'type' => $type->value,
                'occurred_at' => $now,
                'user_id' => $user instanceof User ? $user->getId() : null,
                'detail' => null === $detail ? null : mb_substr($detail, 0, 255),
                'recipients' => [] === $recipients ? null : $recipients,
                'user_agent' => null === $userAgent || '' === $userAgent ? null : mb_substr($userAgent, 0, 255),
                'ip_address' => null === $ipAddress || '' === $ipAddress ? null : mb_substr($ipAddress, 0, 45),
                'proof_path' => $proof?->storagePath,
                'proof_sha256' => $proof?->checksum,
            ],
            [
                'id' => UlidType::NAME,
                'company_id' => UlidType::NAME,
                'kind' => Types::STRING,
                'record_id' => UlidType::NAME,
                'type' => Types::STRING,
                'occurred_at' => Types::DATETIME_IMMUTABLE,
                'user_id' => UlidType::NAME,
                'detail' => Types::STRING,
                'recipients' => Types::JSON,
                'user_agent' => Types::STRING,
                'ip_address' => Types::STRING,
                'proof_path' => Types::STRING,
                'proof_sha256' => Types::STRING,
            ],
        );
    }
}
