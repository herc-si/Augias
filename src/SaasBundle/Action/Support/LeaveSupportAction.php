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

use Augias\UserBundle\Security\SupportAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor stepping out. The request stays taken: they may come back
 * while it runs, and close it from where they answer requests.
 */
final class LeaveSupportAction extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        if ($this->isCsrfTokenValid('support_leave', (string) $request->request->get('_token', ''))) {
            $session = $request->getSession();
            $session->remove('company');
            $session->remove(SupportAccess::SESSION_KEY);
        }

        return $this->redirectToRoute('_support_ended');
    }
}
