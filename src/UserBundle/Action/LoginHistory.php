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

namespace Augias\UserBundle\Action;

use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\LoginRecordRetention;
use Augias\UserBundle\Repository\LoginRecordRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * An account holder's own sign-ins.
 *
 * Their own, and only their own: the page takes the authenticated user rather
 * than an id from the URL, so there is no version of this request that asks
 * about somebody else.
 */
final readonly class LoginHistory
{
    public function __construct(
        private LoginRecordRepository $repository,
    ) {
    }

    /**
     * @return array{records: list<LoginRecord>, retentionDays: int}
     */
    #[Template('@AugiasUser/LoginHistory/index.html.twig')]
    public function __invoke(#[CurrentUser] User $user): array
    {
        return [
            'records' => $this->repository->forUser($user),
            'retentionDays' => LoginRecordRetention::DAYS,
        ];
    }
}
