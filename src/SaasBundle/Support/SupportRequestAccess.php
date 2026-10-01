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

use Augias\SaasBundle\Feature\Feature;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\SupportAccess;
use Augias\UserBundle\Security\SupportPass;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
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
        private FeatureGate $featureGate,
    ) {
    }

    public function passFor(User $user, Ulid $company): ?SupportPass
    {
        if (! $this->desk->isEnabled()) {
            return null;
        }

        foreach ($this->desk->admitting($user->getUserIdentifier()) as $request) {
            // A company that moved to a plan without it closes its door too.
            if ($request->getCompany()->getId()->equals($company) && $this->featureGate->isEnabled(Feature::SupportAccess->value, $request->getCompany())) {
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
