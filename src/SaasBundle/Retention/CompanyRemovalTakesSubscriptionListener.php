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

use Augias\CoreBundle\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Entity\SubscriptionLog;

/**
 * A deleted company takes its subscription with it.
 *
 * The table says so (ON DELETE CASCADE), but SQLite only keeps foreign keys
 * when asked, which Doctrine does not: the subscription would outlive its
 * company and fail every time it is read.
 *
 * @see \Augias\SaasBundle\Tests\Retention\SubscriptionEndRetentionTest
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Company::class)]
final readonly class CompanyRemovalTakesSubscriptionListener
{
    public function preRemove(Company $company, PreRemoveEventArgs $event): void
    {
        $em = $event->getObjectManager();

        foreach ($em->getRepository(Subscription::class)->findBy(['subscriber' => $company]) as $subscription) {
            foreach ($em->getRepository(SubscriptionLog::class)->findBy(['subscription' => $subscription]) as $log) {
                $em->remove($log);
            }

            $em->remove($subscription);
        }
    }
}
