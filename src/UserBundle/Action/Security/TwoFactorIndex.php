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

namespace Augias\UserBundle\Action\Security;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\AuthenticatorAppPolicy;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class TwoFactorIndex extends AbstractController
{
    public function __construct(
        private readonly AuthenticatorAppPolicy $authenticatorAppPolicy,
    ) {
    }

    /**
     * @return array<string, scalar>
     */
    #[Template('@AugiasUser/Security/TwoFactor/index.html.twig')]
    public function __invoke(): array
    {
        $user = $this->getUser();

        return [
            'authenticatorAppMissing' => $user instanceof User && $this->authenticatorAppPolicy->isMissingFor($user),
            'authenticatorAppRequired' => $this->authenticatorAppPolicy->isRequired(),
        ];
    }
}
