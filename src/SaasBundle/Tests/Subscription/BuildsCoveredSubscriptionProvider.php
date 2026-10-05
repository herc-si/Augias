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

namespace Augias\SaasBundle\Tests\Subscription;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\CompanyCoverage;
use Augias\SaasBundle\Feature\Feature;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureType;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Feature\FeatureConfigRegistry;
use SolidWorx\Platform\SaasBundle\Repository\PlanFeatureRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\Clock\MockClock;
use function array_filter;
use function array_values;
use function count;

/**
 * A CoveredSubscriptionProvider over in-memory covers, memberships and rights.
 *
 * @mixin TestCase
 */
trait BuildsCoveredSubscriptionProvider
{
    /**
     * @param list<CompanyCoverage>               $coverages
     * @param array<string, int>                  $allowances plan id => companies right
     * @param list<CompanyCoverage>|null          $persisted  receives the covers created
     *
     * @param-out list<CompanyCoverage> $persisted
     */
    private function coveredSubscriptionProvider(
        SubscriptionProviderInterface $inner,
        array $coverages = [],
        array $allowances = [],
        ?User $owner = null,
        ?array &$persisted = null,
    ): CoveredSubscriptionProvider {
        $persisted = [];
        $matches = static fn (CompanyCoverage $coverage, array $criteria): bool => (! isset($criteria['covered']) || $coverage->getCovered() === $criteria['covered'])
            && (! isset($criteria['host']) || $coverage->getHost() === $criteria['host']);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static function (array $criteria) use (&$coverages, $matches): ?CompanyCoverage {
            foreach ($coverages as $coverage) {
                if ($matches($coverage, $criteria)) {
                    return $coverage;
                }
            }

            return null;
        });
        $repository->method('findBy')->willReturnCallback(static function (array $criteria) use (&$coverages, $matches): array {
            return array_values(array_filter($coverages, static fn (CompanyCoverage $coverage): bool => $matches($coverage, $criteria)));
        });
        $repository->method('count')->willReturnCallback(static function (array $criteria) use (&$coverages, $matches): int {
            return count(array_filter($coverages, static fn (CompanyCoverage $coverage): bool => $matches($coverage, $criteria)));
        });

        $filters = $this->createStub(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getFilters')->willReturn($filters);
        $entityManager->method('persist')->willReturnCallback(static function (object $object) use (&$coverages, &$persisted): void {
            if ($object instanceof CompanyCoverage) {
                $coverages[] = $object;
                $persisted[] = $object;
            }
        });

        $planFeatures = $this->createStub(PlanFeatureRepositoryInterface::class);
        $planFeatures->method('findOneByPlanAndKey')->willReturnCallback(static function (Plan $plan, string $key) use ($allowances): ?PlanFeature {
            if (Feature::Companies->value !== $key || ! isset($allowances[$plan->getPlanId()])) {
                return null;
            }

            return new PlanFeature()->setPlan($plan)->setFeatureKey($key)->setType(FeatureType::INTEGER)->setValue($allowances[$plan->getPlanId()]);
        });

        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('ownedBy')->willReturnCallback(static fn (User $user): array => $user === $owner
            ? array_values(array_filter([...$user->getMemberships()], static fn (Membership $membership): bool => CompanyRole::Owner === $membership->getRole()))
            : []);

        return new CoveredSubscriptionProvider(
            $inner,
            $entityManager,
            $planFeatures,
            new FeatureConfigRegistry([Feature::Companies->value => ['type' => 'integer', 'default' => 1]]),
            $memberships,
            new CompanySelector($this->createStub(ManagerRegistry::class)),
            new MockClock('2026-10-05 10:00:00'),
        );
    }
}
