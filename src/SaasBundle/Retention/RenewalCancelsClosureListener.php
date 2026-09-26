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

namespace Augias\SaasBundle\Retention;

use Augias\CoreBundle\Company\ClosureReason;
use Augias\CoreBundle\Entity\Company;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use Symfony\Bridge\Doctrine\Types\UlidType;
use function spl_object_id;

/**
 * A company closing because its subscription ended is read-only: renewing
 * must give it back at once, not at the next daily run.
 *
 * Written straight to the table — the flush that carries the renewal is
 * already under way — and to the entity, so what is in memory agrees.
 *
 * @see \Augias\SaasBundle\Tests\Retention\SubscriptionEndRetentionTest
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final readonly class RenewalCancelsClosureListener
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $subscription = $event->getObject();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $company = $subscription->getSubscriber();

        if (! $company instanceof Company || ClosureReason::SubscriptionEnded !== $company->getClosureReason()) {
            return;
        }

        if (SubscriptionEndRetention::hasEnded($subscription, DateTimeImmutable::createFromInterface($this->clock->now()))) {
            return;
        }

        $event->getObjectManager()->getConnection()->update(
            Company::TABLE_NAME,
            ['closes_at' => null, 'closure_reminded_at' => null, 'closure_reason' => null],
            ['id' => $company->getId()],
            ['id' => UlidType::NAME],
        );
        $company->cancelClosure();
        // Nothing left for the next flush to write back.
        $event->getObjectManager()->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($company), 'closesAt', null);
        $event->getObjectManager()->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($company), 'closureRemindedAt', null);
        $event->getObjectManager()->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($company), 'closureReason', null);
    }
}
