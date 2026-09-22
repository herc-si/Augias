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

namespace Augias\SaasBundle\Tests\Action;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\ConfigWriter;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\CoreBundle\Telemetry\Telemetry;
use Augias\CoreBundle\Tests\Telemetry\CollectingMessageBus;
use Augias\SaasBundle\Action\SelectPlanAction;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;
use Symfony\Component\DependencyInjection\Container;
use Twig\Environment;

#[CoversClass(SelectPlanAction::class)]
final class SelectPlanActionTest extends TestCase
{
    public function testEmitsPricingPageViewedTelemetryWhenPricingPageRenders(): void
    {
        $bus = new CollectingMessageBus();

        $planRepository = $this->createMock(PlanRepositoryInterface::class);
        $planRepository->expects(self::once())->method('findAllOrdered')->willReturn([
            $this->makePlan('Free'),
            $this->makePlan('Solo'),
        ]);

        $subscriptionProvider = $this->createMock(SubscriptionProviderInterface::class);
        $subscriptionProvider->expects(self::never())->method('getSubscriptionFor')->willReturn(null);

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->expects(self::never())->method('find')->willReturn(new Company());

        // CompanySelector is final; a real instance with no selected company
        // returns null from getCompany(), which short-circuits subscription
        // lookup and lets the action fall through to the pricing render.
        $companySelector = new CompanySelector($this->createStub(ManagerRegistry::class));

        $action = new SelectPlanAction(
            $planRepository,
            $subscriptionProvider,
            $companyRepository,
            $companySelector,
            $this->makeTelemetry($bus),
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturn('<html></html>');

        $container = new Container();
        $container->set('twig', $twig);

        $action->setContainer($container);

        $action();

        self::assertCount(1, $bus->messages);
        self::assertSame('event', $bus->messages[0]->type);
        self::assertSame('saas_pricing_page_viewed', $bus->messages[0]->payload['event']);
    }

    private function makePlan(string $name): Plan
    {
        return new Plan()->setName($name);
    }

    private function makeTelemetry(CollectingMessageBus $bus): Telemetry
    {
        $vault = $this->createMock(AbstractVault::class);
        $vault->expects(self::never())->method('generateKeys')->willReturn(true);

        return new Telemetry(
            $bus,
            new ConfigWriter($vault, '/tmp/augias-test-config'),
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            'build-123',
            true,
            'https://collector.example.com',
            'manual',
            false,
            'en',
            null,
        );
    }
}
