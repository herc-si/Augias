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

namespace Augias\SaasBundle\Support;

use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\SupportAccess;
use Augias\UserBundle\Security\SupportPass;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Ulid;

/**
 * On a hosted deployment, leave comes from the company's requests for help:
 * one that is taken, by this person, and not run out.
 *
 * Switching the feature off in the settings closes every door at once, visits
 * under way included.
 */
#[AsDecorator(decorates: SupportAccess::class)]
final readonly class SupportRequestAccess implements SupportAccess
{
    public function __construct(
        private SupportDesk $desk,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function passFor(User $user, Ulid $company): ?SupportPass
    {
        if (! $this->desk->isEnabled()) {
            return null;
        }

        foreach ($this->desk->admitting($user->getUserIdentifier()) as $request) {
            if ($request->getCompany()->getId()->equals($company)) {
                return new SupportPass($request->getId(), $company, $request->getExpiresAt());
            }
        }

        return null;
    }

    public function hasAnyPass(User $user): bool
    {
        return $this->desk->isEnabled() && [] !== $this->desk->admitting($user->getUserIdentifier());
    }

    public function endedUrl(): string
    {
        return $this->urls->generate('_support_ended');
    }
}
