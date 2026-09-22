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

namespace Augias\CoreBundle\Listener;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\RecordAccess;
use Augias\CoreBundle\Journal\Journalled;
use Augias\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a line when somebody opens a record.
 *
 * On the response rather than the request, and only for a successful one: a
 * page that ended in a 404 or a redirect to the company picker was not a
 * record anyone got to see, and a journal that said otherwise would be
 * answering the wrong question.
 *
 * What it records is whatever the controller was handed. `View(Invoice
 * $invoice)` is resolved by Symfony before the controller runs, so the entity
 * is already loaded and is the one the page showed — no map of routes to
 * maintain, and nothing to keep in step when a route is renamed. A bundle
 * opts its records in by implementing {@see Journalled}.
 *
 * Hence two events rather than one. A resolved argument is not a request
 * attribute — Symfony's value resolvers hand the object to the controller and
 * leave the attributes alone, which is a difference that costs an afternoon
 * if you assume otherwise — so the record is picked up where the arguments
 * are known and written once the response says the page was actually served.
 *
 * @see \Augias\CoreBundle\Tests\Listener\RecordAccessListenerTest
 */
final readonly class RecordAccessListener
{
    /**
     * Where the record waits between the two events below.
     */
    private const string REQUEST_ATTRIBUTE = '_journalled_record';

    /**
     * Opening the same record twice inside this window is one visit.
     *
     * Without it, a reload — or the PDF of the invoice you are looking at —
     * puts a second identical line in a journal whose whole value is being
     * readable at a glance.
     */
    private const string REPEAT_WINDOW = '-5 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanySelector $companySelector,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof Journalled) {
                $event->getRequest()->attributes->set(self::REQUEST_ATTRIBUTE, $argument);

                return;
            }
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest() || ! $event->getResponse()->isSuccessful()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->getMethod() !== Request::METHOD_GET) {
            return;
        }

        $record = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $user = $this->security->getUser();
        $companyId = $this->companySelector->getCompany();

        if (! $record instanceof Journalled || ! $user instanceof User || ! $companyId instanceof Ulid) {
            return;
        }

        $recordId = $record->getId();

        if (! $recordId instanceof Ulid || $this->seenRecently($user, $recordId)) {
            return;
        }

        $this->entityManager->persist(
            new RecordAccess(
                $this->entityManager->getReference(Company::class, $companyId),
                $user,
                $record->journalKind(),
                $recordId,
                $record->journalLabel(),
                $this->clock->now(),
            ),
        );

        $this->entityManager->flush();
    }

    private function seenRecently(User $user, Ulid $recordId): bool
    {
        return $this->entityManager
            ->getRepository(RecordAccess::class)
            ->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.user = :user')
            ->andWhere('a.recordId = :record')
            ->andWhere('a.openedAt > :since')
            // Both are ULIDs stored in their platform's binary form; bound
            // without the type they go down as text and match nothing, which
            // is a duplicate on every reload and no error anywhere.
            ->setParameter('user', $user->getId(), UlidType::NAME)
            ->setParameter('record', $recordId, UlidType::NAME)
            ->setParameter('since', $this->clock->now()->modify(self::REPEAT_WINDOW))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }
}
