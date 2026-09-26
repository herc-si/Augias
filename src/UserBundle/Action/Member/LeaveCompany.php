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
 * A member leaves the company of their own accord. The owner cannot: they
 * hand the company over first, or close it.
 */
final class LeaveCompany extends AbstractController
{
    use MemberActionTrait;

    public function __construct(
        private readonly CompanyAccess $access,
        private readonly MembershipRepository $memberships,
        private readonly MembershipManager $manager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $actor = $this->guard($request, 'member_leave');

        $response = $this->attempt(fn () => $this->manager->leave($actor), 'users.members.left', '_select_company');

        // Still a member: the refusal is shown where they asked.
        return null !== $this->memberships->findOne($actor->getUser(), $actor->getCompany())
            ? $this->redirectToRoute('_users_list')
            : $response;
    }
}
