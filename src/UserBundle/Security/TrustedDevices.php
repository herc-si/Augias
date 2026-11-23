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

use Augias\UserBundle\Entity\TrustedDevice;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Repository\TrustedDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceTokenStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Ulid;
use function random_int;
use function sprintf;

/**
 * Scheb's trusted-device manager, with each device recorded (Rémi,
 * 23/11/2026): the holder sees every browser that skips the second factor,
 * when and from where it was last used, and takes back one or all.
 *
 * The cookie is scheb's own and its signature is what makes it trustworthy;
 * what changes is the version written in it. Scheb's manager writes the
 * account's single version, so all devices look alike; here each device has
 * its own key, and a cookie is honoured only while the row with its key is
 * there. Cookies written before this have no row and ask for the code once.
 *
 * @see \Augias\UserBundle\Tests\Functional\TrustedDeviceTest
 */
final readonly class TrustedDevices implements TrustedDeviceManagerInterface
{
    /**
     * Set on the sign-in request when a trusted device skipped the second
     * factor, so the journal can say so.
     */
    public const string USED_ON_REQUEST = '_augias_trusted_device';

    private const string FIREWALL = 'main';

    public function __construct(
        private TrustedDeviceRepository $repository,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'scheb_two_factor.trusted_token_storage')]
        private TrustedDeviceTokenStorage $tokenStorage,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        #[Autowire(param: 'scheb_two_factor.trusted_device.lifetime')]
        private int $lifetime,
    ) {
    }

    #[Override]
    public function canSetTrustedDevice(object $user, Request $request, string $firewallName): bool
    {
        return $user instanceof User;
    }

    #[Override]
    public function addTrustedDevice(object $user, string $firewallName): void
    {
        if (! $user instanceof User) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        $now = $this->clock->now();

        $device = new TrustedDevice(
            $user,
            random_int(1, 2_147_483_647),
            $now,
            $now->modify(sprintf('+%d seconds', $this->lifetime)),
            $request?->headers->get('User-Agent'),
            $request?->getClientIp(),
        );

        $this->entityManager->persist($device);
        $this->entityManager->flush();

        $this->tokenStorage->addTrustedToken($user->getUserIdentifier(), $firewallName, $device->getKey());
    }

    /**
     * Asked when the second factor is about to be required: a match is a use
     * of the device, and recorded as one.
     */
    #[Override]
    public function isTrustedDevice(object $user, string $firewallName): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $device = $this->match($user, $firewallName);

        if (! $device instanceof TrustedDevice) {
            return false;
        }

        $request = $this->requestStack->getMainRequest();
        $device->usedAt($this->clock->now(), $request?->getClientIp());
        $this->entityManager->flush();
        $request?->attributes->set(self::USED_ON_REQUEST, true);

        return true;
    }

    /**
     * @return list<TrustedDevice>
     */
    public function of(User $user): array
    {
        return $this->repository->activeFor($user, $this->clock->now());
    }

    /**
     * The device this request comes from, if trusted; looking does not count
     * as a use.
     */
    public function current(User $user): ?TrustedDevice
    {
        return $this->match($user, self::FIREWALL);
    }

    public function forget(User $user, Ulid $id): void
    {
        foreach ($this->of($user) as $device) {
            if (! $device->getId()->equals($id)) {
                continue;
            }

            if ($this->tokenStorage->hasTrustedToken($user->getUserIdentifier(), self::FIREWALL, $device->getKey())) {
                $this->tokenStorage->clearTrustedToken($user->getUserIdentifier(), self::FIREWALL);
            }

            $this->entityManager->remove($device);
            $this->entityManager->flush();

            return;
        }
    }

    private function match(User $user, string $firewallName): ?TrustedDevice
    {
        foreach ($this->repository->activeFor($user, $this->clock->now()) as $device) {
            if ($this->tokenStorage->hasTrustedToken($user->getUserIdentifier(), $firewallName, $device->getKey())) {
                return $device;
            }
        }

        return null;
    }
}
