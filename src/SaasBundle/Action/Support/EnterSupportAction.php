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

namespace Augias\SaasBundle\Action\Support;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\SaasBundle\Support\SupportDesk;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\SupportAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Where whoever took a request arrives: into the company it is about, on the
 * leave it gives, under their own name.
 *
 * Signing in is the ordinary one — password and second factor — so nothing
 * here stands in for proving who someone is. What it checks is that this
 * person, now, is the one the company's request admits.
 */
final class EnterSupportAction extends AbstractController
{
    public function __construct(
        private readonly SupportDesk $desk,
        private readonly SupportAccess $access,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $user = $this->getUser();
        $supportRequest = Ulid::isValid($id) ? $this->desk->find(Ulid::fromString($id)) : null;

        if (! $user instanceof User || ! $supportRequest instanceof SupportRequest) {
            throw new NotFoundHttpException();
        }

        $companyId = $supportRequest->getCompany()->getId();

        if (null === $this->access->passFor($user, $companyId)) {
            return $this->redirectToRoute('_support_ended');
        }

        $session = $request->getSession();
        $session->set('company', $companyId);
        $session->set(SupportAccess::SESSION_KEY, $supportRequest->getId());
        $this->companySelector->switchCompany($companyId);

        return $this->redirectToRoute('_dashboard');
    }
}
