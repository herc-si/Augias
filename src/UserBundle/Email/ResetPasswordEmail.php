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

namespace Augias\UserBundle\Email;

use Augias\UserBundle\Entity\User;
use SensitiveParameter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

final class ResetPasswordEmail extends TemplatedEmail
{
    public function __construct(
        User $user,
        #[SensitiveParameter]
        ResetPasswordToken $resetToken,
        string $subject,
    ) {
        parent::__construct();
        $this->to($user->getEmail());
        $this->subject($subject);
        $this->htmlTemplate('@AugiasUser/Email/reset_password.html.twig');
        $this->textTemplate('@AugiasUser/Email/reset_password.txt.twig');
        $this->context([
            'user' => $user,
            'resetToken' => $resetToken,
        ]);
    }
}
