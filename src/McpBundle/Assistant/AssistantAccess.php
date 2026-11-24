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

namespace Augias\McpBundle\Assistant;

use Augias\CoreBundle\Entity\Company;
use Augias\McpBundle\Repository\AssistantEventRepository;
use Augias\SaasBundle\Feature\Feature;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Security\CompanyAccess;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use SolidWorx\Toggler\ToggleInterface;
use function max;

/**
 * Whether the company may ask the assistant now, and why not otherwise: its
 * plan includes no question (SaaS), its owner has not turned it on, or the
 * month's questions are used up.
 *
 * Self-hosted, there is no plan and no quota: only the owner's switch.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantPanelTest
 */
final readonly class AssistantAccess
{
    public function __construct(
        private FeatureGate $featureGate,
        private ToggleInterface $toggle,
        private AssistantEventRepository $events,
        private CompanyAccess $companyAccess,
        private ClockInterface $clock,
    ) {
    }

    public function state(Company $company): AssistantAccessState
    {
        $limit = $this->limit();

        if ($limit === 0) {
            return new AssistantAccessState(AssistantAccessStatus::NotInPlan, 0, 0);
        }

        if (! $company->isAssistantEnabled()) {
            return new AssistantAccessState(AssistantAccessStatus::Off, 0, $limit);
        }

        if ($limit === null) {
            return new AssistantAccessState(AssistantAccessStatus::Ready, 0, null);
        }

        $used = $this->events->countQuestionsSince($company, $this->monthStart());

        return new AssistantAccessState($used >= $limit ? AssistantAccessStatus::QuotaReached : AssistantAccessStatus::Ready, $used, $limit);
    }

    /**
     * Only the owner turns it on or off: it sends the company's data out.
     */
    public function canSwitch(): bool
    {
        return $this->companyAccess->role() === CompanyRole::Owner;
    }

    /**
     * Questions a month, null for no limit.
     */
    private function limit(): ?int
    {
        if (! $this->toggle->isActive('saas_enabled')) {
            return null;
        }

        $value = $this->featureGate->resolve(Feature::AssistantQuestionsPerMonth->value);

        return $value->isUnlimited() ? null : max(0, $value->asInt());
    }

    private function monthStart(): DateTimeImmutable
    {
        return $this->clock->now()->modify('first day of this month')->setTime(0, 0);
    }
}
