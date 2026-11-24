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

namespace Augias\McpBundle\Tests\Assistant;

use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\McpBundle\Assistant\AssistantAccess;
use Augias\McpBundle\Assistant\AssistantAccessStatus;
use Augias\McpBundle\Entity\AssistantEvent;
use Augias\McpBundle\Enum\AssistantEventKind;
use Augias\McpBundle\Repository\AssistantEventRepository;
use Augias\UserBundle\Security\CompanyAccess;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureType;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureValue;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The plan's questions a month (SaaS), the owner's switch, and the month's
 * count, which starts over on the first.
 */
#[CoversClass(AssistantAccess::class)]
#[Group('functional')]
final class AssistantAccessTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testAPlanWithNoQuestionHasNoAssistantEvenTurnedOn(): void
    {
        $company = $this->company(true);

        self::assertSame(AssistantAccessStatus::NotInPlan, $this->access(0)->state($company)->status);
    }

    public function testTheOwnersSwitchComesFirstWhenThePlanHasSome(): void
    {
        self::assertSame(AssistantAccessStatus::Off, $this->access(200)->state($this->company(false))->status);
    }

    public function testTheMonthsQuestionsAreCountedAndStartOverOnTheFirst(): void
    {
        $company = $this->company(true);
        $this->question($company, '2026-10-31 23:00');
        $this->question($company, '2026-11-02 09:00');
        $this->question($company, '2026-11-20 09:00');
        // Not a question: a tool run for one.
        $this->event($company, AssistantEventKind::Tool, '2026-11-20 09:00');

        $state = $this->access(3)->state($company);
        self::assertSame(AssistantAccessStatus::Ready, $state->status);
        self::assertSame(2, $state->used);
        self::assertSame(3, $state->limit);

        $this->question($company, '2026-11-23 09:00');
        self::assertSame(AssistantAccessStatus::QuotaReached, $this->access(3)->state($company)->status);
    }

    public function testNoLimitSelfHostedOrUnlimited(): void
    {
        $company = $this->company(true);

        $selfHosted = $this->access(0, saas: false)->state($company);
        self::assertSame(AssistantAccessStatus::Ready, $selfHosted->status);
        self::assertNull($selfHosted->limit);

        self::assertNull($this->access(FeatureValue::UNLIMITED)->state($company)->limit);
    }

    private function access(int $limit, bool $saas = true): AssistantAccess
    {
        $gate = $this->createStub(FeatureGate::class);
        $gate->method('resolve')->willReturn(new FeatureValue('assistant_questions_per_month', FeatureType::INTEGER, $limit));
        $toggle = $this->createStub(ToggleInterface::class);
        $toggle->method('isActive')->willReturn($saas);

        $events = self::getContainer()->get(AssistantEventRepository::class);
        $companyAccess = self::getContainer()->get(CompanyAccess::class);
        self::assertInstanceOf(AssistantEventRepository::class, $events);
        self::assertInstanceOf(CompanyAccess::class, $companyAccess);

        return new AssistantAccess($gate, $toggle, $events, $companyAccess, new MockClock('2026-11-24 10:00'));
    }

    private function company(bool $enabled): Company
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $company = $em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $enabled ? $company->enableAssistant(new DateTimeImmutable(), 'owner@example.com') : $company->disableAssistant();
        $em->flush();

        return $company;
    }

    private function question(Company $company, string $at): void
    {
        $this->event($company, AssistantEventKind::Question, $at);
    }

    private function event(Company $company, AssistantEventKind $kind, string $at): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(new AssistantEvent($company, null, $kind, null, false, new DateTimeImmutable($at)));
        $em->flush();
    }
}
