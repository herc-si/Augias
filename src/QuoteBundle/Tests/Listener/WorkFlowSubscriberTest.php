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

namespace Augias\QuoteBundle\Tests\Listener;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\NotificationBundle\Notification\NotificationManager;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Listener\WorkFlowSubscriber;
use Augias\QuoteBundle\Mailer\QuoteMailer;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

#[CoversClass(WorkFlowSubscriber::class)]
final class WorkFlowSubscriberTest extends KernelTestCase
{
    use DoctrineTestTrait;
    use MockeryPHPUnitIntegration;

    /**
     * Accepting a quote no longer makes the invoice: it is created when asked
     * for, and numbered then (08/10/2026).
     */
    public function testAcceptingDoesNotCreateAnInvoice(): void
    {
        self::assertArrayNotHasKey('workflow.quote.entered.accepted', WorkFlowSubscriber::getSubscribedEvents());
    }

    public function testOnWorkflowTransitionApplied(): void
    {
        $quote = new Quote()
            ->setClient(ClientFactory::createOne())
            ->setStatus(QuoteStatus::Pending);

        $stateMachine = M::mock(StateMachine::class);

        $notification = M::mock(NotificationManager::class);
        $notification->shouldReceive('sendNotification')
            ->zeroOrMoreTimes();

        $subscriber = new WorkFlowSubscriber(
            $this->registry,
            $notification,
            new QuoteMailer($stateMachine, M::mock(MailerInterface::class), $notification)
        );

        $subscriber->onWorkflowTransitionApplied(new Event($quote, new Marking(['pending' => 1]), new Transition('archive', 'pending', 'archived'), M::mock(WorkflowInterface::class)));

        self::assertTrue($quote->isArchived());
        self::assertSame($quote, $this->em->getRepository(Quote::class)->find($quote->getId()));
    }
}
