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

namespace Augias\UserBundle\Action\Member;

use Augias\UserBundle\Manager\MembershipManager;
use Augias\UserBundle\Repository\MembershipRepository;
use Augias\UserBundle\Security\CompanyAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner hands the company over; they stay on as an administrator.
 */
final class TransferOwnership extends AbstractController
{
    use MemberActionTrait;

    public function __construct(
        private readonly CompanyAccess $access,
        private readonly MembershipRepository $memberships,
        private readonly MembershipManager $manager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $id): RedirectResponse
    {
        $actor = $this->guard($request, 'member_owner_' . $id);
        $member = $this->target($actor, $id);

        return $this->attempt(fn () => $this->manager->transferOwnership($actor, $member), 'users.members.ownership_transferred');
    }
}
