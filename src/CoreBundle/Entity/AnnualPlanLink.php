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

namespace Augias\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A plan's yearly twin: the same offer, the same rights, billed once a year.
 *
 * Each period is a plan of its own — one plan, one price at the payment
 * provider — so checkout, the provider's webhook, proration and the invoices
 * work as they do for any plan. What this records is that the two belong
 * together: the customer sees one offer with a monthly/yearly switch, and
 * moving from one to the other is a change of period, not of offer.
 *
 * The plans are referred to by id rather than by association: they belong
 * to the hosted service's tables, which a self-hosted install does not have,
 * while this table exists on every install like the rest of the core.
 */
#[ORM\Table(name: AnnualPlanLink::TABLE_NAME)]
#[ORM\Entity]
class AnnualPlanLink
{
    final public const string TABLE_NAME = 'plan_annual_link';

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private Ulid $id;

    public function __construct(
        #[ORM\Column(name: 'monthly_plan', type: UlidType::NAME, unique: true)]
        private Ulid $monthlyPlan,
        #[ORM\Column(name: 'annual_plan', type: UlidType::NAME, unique: true)]
        private Ulid $annualPlan,
    ) {
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getMonthlyPlan(): Ulid
    {
        return $this->monthlyPlan;
    }

    public function getAnnualPlan(): Ulid
    {
        return $this->annualPlan;
    }

    public function setAnnualPlan(Ulid $annualPlan): self
    {
        $this->annualPlan = $annualPlan;

        return $this;
    }
}
