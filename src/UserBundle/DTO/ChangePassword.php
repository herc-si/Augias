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

namespace Augias\UserBundle\DTO;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * @see \Augias\UserBundle\Tests\DTO\ChangePasswordTest
 */
final class ChangePassword
{
    #[NotBlank]
    #[UserPassword]
    public ?string $currentPassword = null;

    #[NotBlank(message: 'user.password.not_blank')]
    #[Length(min: 8, max: 4096, minMessage: 'user.password.min_length_long')]
    #[PasswordStrength(
        minScore: PasswordStrength::STRENGTH_MEDIUM,
        // Its own message: the advice has to match the stricter bar
        message: 'user.password.weak_stronger'
    )]
    #[NotCompromisedPassword(
        message: 'user.password.compromised'
    )]
    public ?string $plainPassword = null;
}
