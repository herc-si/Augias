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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SaasBundle\DependencyInjection\Compiler\PlanFeatureCachePass;
use Augias\SaasBundle\Feature\Feature;
use Augias\Test\SaasKernel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 05/10/2026: a right changed from the operator console was not seen by the
 * app, each container keeping its own cache of the rights in files.
 */
#[CoversClass(PlanFeatureCachePass::class)]
#[Group('functional')]
#[Group('saas-kernel')]
final class PlanFeatureCacheTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    #[Override]
    protected static function getKernelClass(): string
    {
        return SaasKernel::class;
    }

    public function testTheRightsHaveACacheOfTheirOwn(): void
    {
        $manager = $this->manager();
        $cache = new ReflectionProperty(PlanFeatureManager::class, 'cache')->getValue($manager);

        self::assertSame(self::getContainer()->get(PlanFeatureCachePass::POOL), $cache);
        self::assertNotSame(self::getContainer()->get('cache.app'), $cache);
    }

    /**
     * What another process sees: the right as the database has it now, not
     * as this one cached it.
     */
    public function testARightChangedElsewhereIsReadAfresh(): void
    {
        $plan = new Plan()->setName('Agence')->setPlanId('price_agence_cache')->setPrice(3999);
        $this->em()->persist($plan);
        $this->em()->flush();

        $this->manager()->setFeature($plan, Feature::Companies->value, 5);
        self::assertSame(5, $this->manager()->getFeature($plan, Feature::Companies->value)->asInt());

        // Changed behind this process's back, as the console would.
        $updated = $this->em()->createQuery('UPDATE ' . PlanFeature::class . ' f SET f.value = :value WHERE f.plan = :plan AND f.featureKey = :key')
            ->setParameter('value', 2, Types::JSON)
            ->setParameter('plan', $plan->getId(), UlidType::NAME)
            ->setParameter('key', Feature::Companies->value)
            ->execute();
        self::assertSame(1, $updated);

        // The next request.
        self::getContainer()->get('services_resetter')->reset();

        self::assertSame(2, $this->manager()->getFeature($plan, Feature::Companies->value)->asInt());
    }

    private function manager(): PlanFeatureManager
    {
        // Only the SaaS kernel has it; PHPStan reads the self-hosted container.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $manager = self::getContainer()->get(PlanFeatureManager::class);
        self::assertInstanceOf(PlanFeatureManager::class, $manager);

        return $manager;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
