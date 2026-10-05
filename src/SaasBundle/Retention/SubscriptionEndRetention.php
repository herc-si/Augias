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
use Augias\CoreBundle\Company\ClosureSchedule;
use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Entity\Company;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use function array_map;
use function in_array;
use function max;
use function sprintf;

/**
 * What the terms of service promise once a subscription is over: the data
 * stays RETENTION_DAYS so it can be exported, then goes.
 *
 * Rather than a second deletion path, the end of a subscription schedules the
 * company's closure, with its own reason: the reminder a week before, the
 * deletion itself and the emails are those of a closure the owner asks for.
 * Renewing calls it off — here each day, and at once through
 * {@see RenewalCancelsClosureListener}.
 *
 * @see \Augias\SaasBundle\Tests\Retention\SubscriptionEndRetentionTest
 */
final readonly class SubscriptionEndRetention implements ClosureSchedule
{
    public const int RETENTION_DAYS = 90;

    /** The statuses after which nothing more is billed: the contract is over once the end date has passed. */
    private const array ENDED = [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED, SubscriptionStatus::TRIAL];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyClosure $closure,
        private ClockInterface $clock,
        private CoveredSubscriptionProvider $coverage,
    ) {
    }

    public static function hasEnded(Subscription $subscription, DateTimeImmutable $now): bool
    {
        return in_array($subscription->getStatus(), self::ENDED, true) && $subscription->getEndDate() <= $now;
    }

    public function reconcile(): void
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        // Those ended, and those whose company is closing on an end since renewed.
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->entityManager->createQueryBuilder()
            ->select('s', 'c')
            ->from(Subscription::class, 's')
            ->join('s.subscriber', 'c')
            ->where('s.status IN (:ended) AND s.endDate <= :now')
            ->orWhere('c.closureReason = :reason')
            ->setParameter('ended', array_map(static fn (SubscriptionStatus $status): string => $status->value, self::ENDED))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('reason', ClosureReason::SubscriptionEnded->value)
            ->getQuery()
            ->getResult();

        foreach ($subscriptions as $subscription) {
            $company = $subscription->getSubscriber();

            if (! $company instanceof Company) {
                continue;
            }

            $ended = self::hasEnded($subscription, $now);

            // The companies this subscription paid for go, or stay, with it.
            foreach ([$company, ...$this->coverage->coveredBy($company)] as $target) {
                $reason = $target->getClosureReason();

                if ($ended && null === $reason) {
                    $this->closure->scheduleAt($target, $this->deletionDate($subscription, $now), ClosureReason::SubscriptionEnded);
                } elseif (! $ended && ClosureReason::SubscriptionEnded === $reason) {
                    $this->closure->cancel($target);
                }
            }
        }
    }

    /**
     * RETENTION_DAYS after the end — but never with less notice than an owner
     * gets when asking, for a subscription that ended before this was in place.
     */
    private function deletionDate(Subscription $subscription, DateTimeImmutable $now): DateTimeImmutable
    {
        $end = DateTimeImmutable::createFromInterface($subscription->getEndDate());

        return max(
            $end->modify(sprintf('+%d days', self::RETENTION_DAYS)),
            $now->modify(sprintf('+%d days', CompanyClosure::GRACE_DAYS)),
        );
    }
}
