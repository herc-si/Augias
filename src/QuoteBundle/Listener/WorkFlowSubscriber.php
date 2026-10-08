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

namespace Augias\QuoteBundle\Listener;

use Augias\NotificationBundle\Notification\NotificationManager;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Exception\InvalidTransitionException;
use Augias\QuoteBundle\Mailer\QuoteMailer;
use Augias\QuoteBundle\Model\Graph as QuoteGraph;
use Augias\QuoteBundle\Notification\QuoteStatusNotification;
use Doctrine\Persistence\ManagerRegistry;
use JsonException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Transition;

/**
 * @see \Augias\QuoteBundle\Tests\Listener\WorkFlowSubscriberTest
 */
final readonly class WorkFlowSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private NotificationManager $notification,
        private QuoteMailer $quoteMailer
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.quote.entered' => 'onWorkflowTransitionApplied',
        ];
    }

    /**
     * @template TSubject of object
     *
     * @param Event<TSubject> $event
     *
     * @throws JsonException|InvalidTransitionException|TransportExceptionInterface
     */
    public function onWorkflowTransitionApplied(Event $event): void
    {
        /** @var Quote $quote */
        $quote = $event->getSubject();

        if (($transition = $event->getTransition()) instanceof Transition && QuoteGraph::TRANSITION_ARCHIVE === $transition->getName()) {
            $quote->archive();
        }

        $em = $this->registry->getManager();

        $em->persist($quote);
        $em->flush();

        if (($transition = $event->getTransition()) instanceof Transition && QuoteGraph::TRANSITION_SEND === $transition->getName()) {
            $this->quoteMailer->send($quote);
        }

        if (QuoteStatus::New !== $quote->getStatus()) {
            $this->notification->sendNotification(new QuoteStatusNotification(['quote' => $quote]));
        }
    }
}
