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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Repository\MembershipRepository;
use Augias\UserBundle\Repository\UserInvitationRepository;
use Augias\UserBundle\Repository\UserRepository;
use Augias\UserBundle\Security\CompanyAccess;
use Symfony\Bridge\Twig\Attribute\Template;

final readonly class Users
{
    public function __construct(
        private UserRepository $userRepository,
        private UserInvitationRepository $invitationRepository,
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private MembershipRepository $membershipRepository,
        private CompanyAccess $access,
    ) {
    }

    /**
     * @return array{totalActiveUsers: int, totalPendingInvitations: int, recentlyJoinedCount: int, seatsUsage: int, members: list<Membership>, me: ?Membership}
     */
    #[Template('@AugiasUser/Users/index.html.twig')]
    public function __invoke(): array
    {
        $totalActiveUsers = $this->userRepository->getUserCount();
        $totalPendingInvitations = $this->invitationRepository->countPendingInvitations();
        $recentlyJoinedCount = $this->userRepository->getRecentlyJoinedCount(30);

        $company = $this->companyRepository->find($this->companySelector->getCompany());
        $seatsUsage = $company instanceof Company
            ? $this->userRepository->getUserCountForCompany($company)
                + $this->invitationRepository->countPending($company)
            : 0;

        return [
            'totalActiveUsers' => $totalActiveUsers,
            'totalPendingInvitations' => $totalPendingInvitations,
            'recentlyJoinedCount' => $recentlyJoinedCount,
            'seatsUsage' => $seatsUsage,
            'members' => $company instanceof Company ? $this->membershipRepository->forCompany($company) : [],
            'me' => $this->access->membership(),
        ];
    }
}
