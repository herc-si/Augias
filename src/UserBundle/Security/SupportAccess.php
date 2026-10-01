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

namespace Augias\UserBundle\Security;

use Augias\UserBundle\Entity\User;
use Symfony\Component\Uid\Ulid;

/**
 * Whether someone outside a company has been let in to help it.
 *
 * Nothing in a self-hosted install lets anyone in: {@see NoSupportAccess} is
 * the answer there. A hosted deployment replaces it with one that reads the
 * company's requests for help.
 */
interface SupportAccess
{
    /**
     * Where the session remembers the request a visit is made on.
     */
    public const string SESSION_KEY = 'support_request';

    /**
     * The leave this person holds on this company now, if any.
     */
    public function passFor(User $user, Ulid $company): ?SupportPass;

    /**
     * Whether this person holds leave on any company now — someone who is
     * nobody's member but has somewhere to be.
     */
    public function hasAnyPass(User $user): bool;

    /**
     * Where to send someone whose visit has just ended, or null to send them
     * wherever anyone without a company goes.
     */
    public function endedUrl(): ?string;
}
