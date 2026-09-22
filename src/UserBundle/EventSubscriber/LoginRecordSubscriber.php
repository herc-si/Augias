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

namespace Augias\UserBundle\EventSubscriber;

use Augias\UserBundle\Entity\LoginRecord;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\LoginOutcome;
use Augias\UserBundle\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use function is_string;

/**
 * Writes the account journal: who signed in, who failed, from where.
 *
 * Success is recorded when the person is *actually* signed in, which is not
 * where it looks like it happens. With a second factor configured, the
 * security bundle fires `LoginSuccessEvent` as soon as the password checks
 * out, while the session still holds nothing but a challenge. Recording there
 * would report a success for someone who never got past the code — the one
 * case the journal exists to show. So a login that still owes a second factor
 * is left alone here and recorded when the second factor completes.
 *
 * @see \Augias\UserBundle\Tests\EventSubscriber\LoginRecordSubscriberTest
 */
final readonly class LoginRecordSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            TwoFactorAuthenticationEvents::COMPLETE => 'onTwoFactorComplete',
            TwoFactorAuthenticationEvents::FAILURE => 'onTwoFactorFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            // Not signed in yet — see the class docblock.
            return;
        }

        $user = $event->getUser();

        if (! $user instanceof User) {
            return;
        }

        $this->record($user, $user->getUserIdentifier(), LoginOutcome::Success);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $identifier = $this->attemptedIdentifier($event);

        if ($identifier === null) {
            return;
        }

        // An attempt on an address nobody owns is still worth a row: a run of
        // them is what someone guessing looks like. It simply has no account
        // to belong to, and so no tenant can read it.
        $this->record($this->findUser($identifier), $identifier, LoginOutcome::Failure);
    }

    public function onTwoFactorComplete(TwoFactorAuthenticationEvent $event): void
    {
        $this->recordFromToken($event->getToken(), LoginOutcome::Success);
    }

    public function onTwoFactorFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->recordFromToken($event->getToken(), LoginOutcome::TwoFactorFailure);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();

        if (! $token instanceof TokenInterface) {
            return;
        }

        $this->recordFromToken($token, LoginOutcome::SignedOut);
    }

    private function recordFromToken(TokenInterface $token, LoginOutcome $outcome): void
    {
        $user = $token->getUser();

        if (! $user instanceof User) {
            return;
        }

        $this->record($user, $user->getUserIdentifier(), $outcome);
    }

    private function record(?User $user, string $identifier, LoginOutcome $outcome): void
    {
        $request = $this->requestStack->getMainRequest();

        $this->entityManager->persist(
            new LoginRecord(
                $user,
                $identifier,
                $outcome,
                $this->clock->now(),
                $request?->getClientIp(),
                $request instanceof Request ? $request->headers->get('User-Agent') : null,
            ),
        );

        $this->entityManager->flush();
    }

    private function attemptedIdentifier(LoginFailureEvent $event): ?string
    {
        $badge = $event->getPassport()?->getBadge(UserBadge::class);

        if ($badge instanceof UserBadge) {
            return $badge->getUserIdentifier();
        }

        // No passport when the authenticator rejected the request before
        // building one; the form field is then all there is to go on.
        $submitted = $event->getRequest()->request->get('_username');

        return is_string($submitted) && $submitted !== '' ? $submitted : null;
    }

    /**
     * The account behind the attempted address, if there is one.
     *
     * Looked up without a company in scope, which is the state every sign-in
     * starts from: `CompanyFilter` only constrains users when a company has
     * been selected, and at this point none has.
     */
    private function findUser(string $identifier): ?User
    {
        $user = $this->users->findOneBy(['email' => $identifier]);

        return $user instanceof User ? $user : null;
    }
}
