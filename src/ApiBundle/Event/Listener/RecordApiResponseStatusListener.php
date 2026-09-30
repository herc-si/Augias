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

namespace Augias\ApiBundle\Event\Listener;

use Augias\UserBundle\Entity\ApiTokenHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Writes the response's status code on the API request history.
 *
 * The history entry is made when the token is authenticated, before there is
 * a response, so its status was never set and every row of the token's history
 * showed "-" (30/09/2026). The authenticator leaves the entry on the request;
 * this completes it with whatever answered, errors and refusals included.
 *
 * @see \Augias\ApiBundle\Tests\Event\Listener\RecordApiResponseStatusListenerTest
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
final readonly class RecordApiResponseStatusListener
{
    public const string REQUEST_ATTRIBUTE = '_api_token_history';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $history = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $history instanceof ApiTokenHistory || ! $this->entityManager->isOpen()) {
            return;
        }

        $history->setStatusCode($event->getResponse()->getStatusCode());

        try {
            $this->entityManager->flush();
        } catch (Throwable) {
            // The history is a courtesy to the token's owner: failing to
            // complete it must not turn an answered request into an error.
        }
    }
}
