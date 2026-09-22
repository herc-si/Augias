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

namespace Augias\CoreBundle\Action;

use Augias\CoreBundle\AccessJournalRetention;
use Augias\CoreBundle\Entity\RecordAccess;
use Augias\CoreBundle\Repository\RecordAccessRepository;
use Augias\UserBundle\Entity\User;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where you have been: the records you opened, most recent first.
 *
 * Yours and only yours. The page takes the authenticated user rather than an
 * id from the URL, so there is no version of this request that asks about a
 * colleague — this is a memory aid, not a way to watch someone work.
 */
final readonly class AccessLog
{
    public function __construct(
        private RecordAccessRepository $repository,
    ) {
    }

    /**
     * @return array{records: list<RecordAccess>, retentionDays: int}
     */
    #[Template('@AugiasCore/AccessLog/index.html.twig')]
    public function __invoke(#[CurrentUser] User $user): array
    {
        return [
            'records' => $this->repository->forUser($user),
            'retentionDays' => AccessJournalRetention::DAYS,
        ];
    }
}
