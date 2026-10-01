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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * The company closing its door before the time it set — at once, visit under
 * way included.
 */
final class RevokeSupportAction extends AbstractController
{
    public function __construct(
        private readonly SupportDesk $desk,
        private readonly CompanySelector $companySelector,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $user = $this->getUser();
        $supportRequest = Ulid::isValid($id) ? $this->desk->find(Ulid::fromString($id)) : null;
        $companyId = $this->companySelector->getCompany();

        // Only the open company's own requests: the lookup itself sees past
        // the company filter.
        if (! $user instanceof User || ! $supportRequest instanceof SupportRequest || ! $companyId instanceof Ulid || ! $supportRequest->getCompany()->getId()->equals($companyId)) {
            throw new NotFoundHttpException();
        }

        if (! $this->isCsrfTokenValid('support_revoke_' . $id, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'support.flash.invalid_token');

            return $this->redirectToRoute('_support');
        }

        if ($supportRequest->getStatus()->isOpen()) {
            $this->desk->revoke($supportRequest, $user->getUserIdentifier());
            $this->addFlash('success', 'support.flash.revoked');
        }

        return $this->redirectToRoute('_support');
    }
}
