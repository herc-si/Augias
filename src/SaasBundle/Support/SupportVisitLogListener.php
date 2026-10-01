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

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\OperatorAccess;
use Augias\CoreBundle\Enum\AccessReason;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Security\SupportPass;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function mb_substr;

/**
 * Every page a visitor opens inside a company goes in the company's access
 * log, before anything is shown — a page that fails to render was still a
 * look — and including the ones they are refused: an attempt is part of the
 * account too.
 *
 * Priority 6: after the firewall has said who this is and after the company
 * has been opened (7), before any controller runs.
 */
final readonly class SupportVisitLogListener
{
    public function __construct(
        private CompanyAccess $access,
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
    public function onRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $pass = $this->access->pass();
        $user = $this->security->getUser();

        if (! $pass instanceof SupportPass || ! $user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        $this->entityManager->persist(new OperatorAccess(
            $this->entityManager->getReference(Company::class, $pass->companyId),
            $user->getUserIdentifier(),
            AccessReason::SupportSession,
            $this->clock->now(),
            mb_substr($request->getMethod() . ' ' . $request->getRequestUri(), 0, 255),
        ));
        $this->entityManager->flush();
    }
}
