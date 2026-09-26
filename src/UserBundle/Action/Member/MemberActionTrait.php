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

use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Exception\MembershipRuleViolation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * What the member actions share: a POST with its token, the member acting,
 * the member acted on — in the same company — and a refusal shown as a
 * message on the members page rather than an error.
 *
 * @property \Augias\UserBundle\Security\CompanyAccess     $access
 * @property \Augias\UserBundle\Repository\MembershipRepository $memberships
 */
trait MemberActionTrait
{
    private function guard(Request $request, string $tokenId): Membership
    {
        if (! $request->isMethod(Request::METHOD_POST) || ! $this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid request.');
        }

        $actor = $this->access->membership();

        if (! $actor instanceof Membership) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }

    private function target(Membership $actor, string $userId): Membership
    {
        if (! Ulid::isValid($userId)) {
            throw new NotFoundHttpException();
        }

        $member = $this->memberships->findOne(Ulid::fromString($userId), $actor->getCompany());

        if (! $member instanceof Membership) {
            throw new NotFoundHttpException();
        }

        return $member;
    }

    /**
     * @param callable(): void $change
     */
    private function attempt(callable $change, string $success, string $route = '_users_list'): RedirectResponse
    {
        try {
            $change();
            $this->addFlash('success', $success);
        } catch (MembershipRuleViolation $violation) {
            $this->addFlash('danger', $violation->trans($this->translator));
        }

        return $this->redirectToRoute($route);
    }
}
