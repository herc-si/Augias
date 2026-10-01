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

namespace Augias\SaasBundle\Twig;

use Augias\SaasBundle\Plan\PlanPeriods;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * What the plan pages need to show an offer with two periods, and every
 * price in the currency the service sells in.
 */
final readonly class PlanPeriodExtension
{
    public function __construct(
        private PlanPeriods $periods,
        private TranslatorInterface $translator,
        /**
         * The service's own prices are in the currency it sells in, which is
         * the deployment's default — not any customer's.
         */
        #[Autowire(env: 'AUGIAS_DEFAULT_CURRENCY')]
        private string $currency = 'EUR',
    ) {
    }

    #[AsTwigFunction('plan_annual')]
    public function annual(Plan $plan): ?Plan
    {
        return $this->periods->annualOf($plan);
    }

    /**
     * `plan_label(plan)`: the plan's name, saying when it is the yearly one —
     * "Solo" and "Solo" would otherwise read as no change at all.
     */
    #[AsTwigFunction('plan_label')]
    public function label(Plan $plan): string
    {
        return $this->periods->isAnnual($plan)
            ? $this->translator->trans('saas.plan.annual_label', ['%plan%' => $plan->getName()])
            : $plan->getName();
    }

    #[AsTwigFunction('plan_is_annual')]
    public function isAnnual(Plan $plan): bool
    {
        return $this->periods->isAnnual($plan);
    }

    #[AsTwigFunction('plan_monthly_equivalent')]
    public function monthlyEquivalent(Plan $plan): int
    {
        return $this->periods->monthlyEquivalent($plan);
    }

    #[AsTwigFunction('plan_yearly_saving')]
    public function yearlySaving(Plan $monthly, Plan $annual): int
    {
        return $this->periods->yearlySaving($monthly, $annual);
    }

    /**
     * `plan_currency()`: the currency the service's prices are in.
     */
    #[AsTwigFunction('plan_currency')]
    public function currency(): string
    {
        return $this->currency;
    }
}
