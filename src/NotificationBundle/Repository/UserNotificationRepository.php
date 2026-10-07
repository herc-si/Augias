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

namespace Augias\NotificationBundle\Repository;

use Augias\CoreBundle\Entity\Company;
use Augias\NotificationBundle\Entity\UserNotification;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<UserNotification>
 */
final class UserNotificationRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserNotification::class);
    }

    /**
     * Who asked to hear about $event in $company — or, with no company given,
     * in the company the request runs in. Null when neither is known: run from
     * cron the company filter is off, and the settings of every tenant would
     * come back, sending one company's invoice to another's users.
     *
     * @return list<UserNotification>|null
     */
    public function findSubscribers(string $event, ?Company $company): ?array
    {
        if ($company instanceof Company) {
            return $this->findBy(['event' => $event, 'company' => $company]);
        }

        if (! $this->getEntityManager()->getFilters()->isEnabled('company')) {
            return null;
        }

        return $this->findBy(['event' => $event]);
    }
}
