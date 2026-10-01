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

namespace Augias\SaasBundle\Plan;

use Augias\CoreBundle\Entity\AnnualPlanLink;
use Doctrine\ORM\EntityManagerInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Service\ResetInterface;
use function array_values;
use function intdiv;

/**
 * Which plans are the yearly twin of another, and what that means for price.
 *
 * A yearly plan is a plan of its own (see {@see AnnualPlanLink}); this is
 * the one place that knows the pairs, so the plan pages show one offer with
 * two periods, and a change between periods is weighed by what it costs per
 * month rather than by the amount billed at once.
 */
final class PlanPeriods implements ResetInterface
{
    /** @var array<string, string>|null monthly plan id => yearly plan id */
    private ?array $pairs = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isAnnual(Plan $plan): bool
    {
        return in_array($plan->getId()->toBase32(), $this->pairs(), true);
    }

    /**
     * The yearly twin of a monthly plan, if it has one on sale.
     */
    public function annualOf(Plan $plan): ?Plan
    {
        $annualId = $this->pairs()[$plan->getId()->toBase32()] ?? null;

        if (null === $annualId) {
            return null;
        }

        $annual = $this->entityManager->find(Plan::class, Ulid::fromBase32($annualId));

        return $annual instanceof Plan && $annual->isActive() ? $annual : null;
    }

    /**
     * The yearly twin of a monthly plan, on sale or not: what the operator's
     * tooling edits, so that it never makes a second one.
     */
    public function annualTwinOf(Plan $plan): ?Plan
    {
        $annualId = $this->pairs()[$plan->getId()->toBase32()] ?? null;
        $annual = null === $annualId ? null : $this->entityManager->find(Plan::class, Ulid::fromBase32($annualId));

        return $annual instanceof Plan ? $annual : null;
    }

    /**
     * The monthly plan a yearly one belongs to.
     */
    public function monthlyOf(Plan $plan): ?Plan
    {
        $monthlyId = array_search($plan->getId()->toBase32(), $this->pairs(), true);

        if (false === $monthlyId) {
            return null;
        }

        $monthly = $this->entityManager->find(Plan::class, Ulid::fromBase32($monthlyId));

        return $monthly instanceof Plan ? $monthly : null;
    }

    /**
     * What the plan costs per month, in cents: a yearly price spread over
     * twelve months. What decides whether a change is an upgrade.
     */
    public function monthlyEquivalent(Plan $plan): int
    {
        return $this->isAnnual($plan) ? intdiv($plan->getPrice(), 12) : $plan->getPrice();
    }

    /**
     * Whether moving from one plan to another is a step down — taken at the
     * end of the period paid for rather than at once.
     *
     * Within one offer, going yearly is a step up (a year is paid now) and
     * going monthly a step down. Between offers, what counts is the price per
     * month: a year billed at once is not dearer than a monthly plan that
     * costs more each month.
     */
    public function isDowngrade(Plan $from, Plan $to): bool
    {
        if ($this->sameOffer($from, $to)) {
            return $this->isAnnual($from) && ! $this->isAnnual($to);
        }

        return $this->monthlyEquivalent($to) < $this->monthlyEquivalent($from);
    }

    public function sameOffer(Plan $one, Plan $other): bool
    {
        $offer = static fn (Plan $plan, ?Plan $monthly): string => ($monthly ?? $plan)->getId()->toBase32();

        return $offer($one, $this->monthlyOf($one)) === $offer($other, $this->monthlyOf($other));
    }

    /**
     * The plans to show as offers: every plan except the yearly twins, which
     * are shown on their monthly plan's card.
     *
     * @param list<Plan> $plans
     *
     * @return list<Plan>
     */
    public function offers(array $plans): array
    {
        return array_values(array_filter($plans, fn (Plan $plan): bool => ! $this->isAnnual($plan)));
    }

    /**
     * What a year on the yearly plan saves against twelve months, in cents;
     * zero when it does not.
     */
    public function yearlySaving(Plan $monthly, Plan $annual): int
    {
        return max(0, $monthly->getPrice() * 12 - $annual->getPrice());
    }

    public function link(Plan $monthly, Plan $annual): void
    {
        $repository = $this->entityManager->getRepository(AnnualPlanLink::class);
        $link = $repository->findOneBy(['monthlyPlan' => $monthly->getId()]);

        if ($link instanceof AnnualPlanLink) {
            $link->setAnnualPlan($annual->getId());
        } else {
            $this->entityManager->persist(new AnnualPlanLink($monthly->getId(), $annual->getId()));
        }

        $this->entityManager->flush();
        $this->pairs = null;
    }

    public function unlink(Plan $monthly): void
    {
        $link = $this->entityManager->getRepository(AnnualPlanLink::class)->findOneBy(['monthlyPlan' => $monthly->getId()]);

        if ($link instanceof AnnualPlanLink) {
            $this->entityManager->remove($link);
            $this->entityManager->flush();
        }

        $this->pairs = null;
    }

    public function reset(): void
    {
        $this->pairs = null;
    }

    /**
     * @return array<string, string>
     */
    private function pairs(): array
    {
        if (null === $this->pairs) {
            $this->pairs = [];

            foreach ($this->entityManager->getRepository(AnnualPlanLink::class)->findAll() as $link) {
                $this->pairs[$link->getMonthlyPlan()->toBase32()] = $link->getAnnualPlan()->toBase32();
            }
        }

        return $this->pairs;
    }
}
