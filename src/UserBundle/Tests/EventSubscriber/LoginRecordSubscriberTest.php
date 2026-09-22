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

namespace Augias\UserBundle\Tests\EventSubscriber;

use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\LoginOutcome;
use Augias\UserBundle\EventSubscriber\LoginRecordSubscriber;
use Augias\UserBundle\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The case worth a unit test is the one a functional test cannot stage
 * cheaply: a login that still owes a second factor must not be recorded as a
 * success, because at that point the person is not signed in.
 */
#[CoversClass(LoginRecordSubscriber::class)]
final class LoginRecordSubscriberTest extends TestCase
{
    use M\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function testALoginStillOwingASecondFactorIsNotRecordedYet(): void
    {
        $entityManager = M::mock(EntityManagerInterface::class);
        $entityManager->shouldNotReceive('persist');
        $entityManager->shouldNotReceive('flush');

        $event = M::mock(LoginSuccessEvent::class);
        $event->shouldReceive('getAuthenticatedToken')
            ->andReturn(M::mock(TwoFactorTokenInterface::class));

        $this->subscriber($entityManager)->onLoginSuccess($event);
    }

    public function testAFullySignedInLoginIsRecorded(): void
    {
        $user = new User();
        $user->setEmail('someone@example.com');

        $recorded = null;

        $entityManager = M::mock(EntityManagerInterface::class);
        $entityManager->shouldReceive('persist')
            ->once()
            ->with(M::on(static function (LoginRecord $record) use (&$recorded): bool {
                $recorded = $record;
                return true;
            }));
        $entityManager->shouldReceive('flush')->once();

        $event = M::mock(LoginSuccessEvent::class);
        $event->shouldReceive('getAuthenticatedToken')->andReturn(M::mock(TokenInterface::class));
        $event->shouldReceive('getUser')->andReturn($user);

        $this->subscriber($entityManager)->onLoginSuccess($event);

        self::assertInstanceOf(LoginRecord::class, $recorded);
        self::assertSame(LoginOutcome::Success, $recorded->getOutcome());
        self::assertSame('someone@example.com', $recorded->getIdentifier());
        self::assertSame('192.0.2.7', $recorded->getIpAddress());
    }

    /**
     * An attempt on an address that belongs to nobody still leaves a row, with
     * no account attached — which is what keeps it out of every tenant's view.
     */
    public function testAnAttemptOnAnUnknownAddressIsRecordedWithoutAnAccount(): void
    {
        $recorded = null;

        $entityManager = M::mock(EntityManagerInterface::class);
        $entityManager->shouldReceive('persist')
            ->once()
            ->with(M::on(static function (LoginRecord $record) use (&$recorded): bool {
                $recorded = $record;
                return true;
            }));
        $entityManager->shouldReceive('flush')->once();

        $users = M::mock(UserRepository::class);
        $users->shouldReceive('findOneBy')->with(['email' => 'nobody@example.com'])->andReturnNull();

        $event = M::mock(LoginFailureEvent::class);
        $event->shouldReceive('getPassport')->andReturn(
            new Passport(new UserBadge('nobody@example.com'), M::mock(
                \Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CredentialsInterface::class
            )),
        );

        $this->subscriber($entityManager, $users)->onLoginFailure($event);

        self::assertInstanceOf(LoginRecord::class, $recorded);
        self::assertNull($recorded->getUser());
        self::assertSame(LoginOutcome::Failure, $recorded->getOutcome());
        self::assertSame('nobody@example.com', $recorded->getIdentifier());
    }

    private function subscriber(
        EntityManagerInterface $entityManager,
        ?UserRepository $users = null,
    ): LoginRecordSubscriber {
        $request = Request::create('/login', server: ['REMOTE_ADDR' => '192.0.2.7']);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $clock = M::mock(ClockInterface::class);
        $clock->shouldReceive('now')->andReturn(new DateTimeImmutable('2026-09-22 10:00:00'));

        return new LoginRecordSubscriber(
            $entityManager,
            $users ?? M::mock(UserRepository::class),
            $requestStack,
            $clock,
        );
    }
}
