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

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a visitor lands once out of the company — left, revoked, or run out.
 * They have no company of their own to be sent to.
 */
final class SupportEndedAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@AugiasSaas/support/ended.html.twig');
    }
}
