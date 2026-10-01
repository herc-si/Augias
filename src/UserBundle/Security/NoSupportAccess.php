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
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Ulid;

/**
 * Nobody is let in who is not a member. The answer on a self-hosted install.
 */
#[AsAlias(id: SupportAccess::class)]
final class NoSupportAccess implements SupportAccess
{
    public function passFor(User $user, Ulid $company): ?SupportPass
    {
        return null;
    }

    public function hasAnyPass(User $user): bool
    {
        return false;
    }

    public function endedUrl(): ?string
    {
        return null;
    }
}
