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
use Augias\UserBundle\LoginRecordRetention;
use Augias\UserBundle\Repository\LoginRecordRepository;
use Symfony\Bridge\Twig\Attribute\Template;

/**
 * Who has been signing in to the company's accounts.
 *
 * Every member sees it, because Augias has no notion of an administrator: a
 * member already sees the company's clients, invoices and the list of people
 * who can reach them. Withholding *this* from the same people would not
 * protect anyone; it would only mean nobody notices the attempts.
 *
 * The scoping is the join in the repository, not a check here — see
 * LoginRecordRepository::forCompanyMembers().
 */
final readonly class CompanyLoginHistory
{
    public function __construct(
        private LoginRecordRepository $repository,
    ) {
    }

    /**
     * @return array{records: list<LoginRecord>, retentionDays: int}
     */
    #[Template('@AugiasUser/Users/logins.html.twig')]
    public function __invoke(): array
    {
        return [
            'records' => $this->repository->forCompanyMembers(),
            'retentionDays' => LoginRecordRetention::DAYS,
        ];
    }
}
