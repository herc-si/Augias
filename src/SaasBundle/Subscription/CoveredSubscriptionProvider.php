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

namespace Augias\SaasBundle\Subscription;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\CompanyCoverage;
use Augias\SaasBundle\Feature\Feature;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Repository\MembershipRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Feature\FeatureConfigRegistry;
use SolidWorx\Platform\SaasBundle\Repository\PlanFeatureRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Service\ResetInterface;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function max;

/**
 * Answers "which subscription is this company on?" with the host's, for a
 * company another one's subscription covers (an agency plan paying for the
 * companies its owner manages, see {@see CompanyCoverage}).
 *
 * Everything that reads a company's subscription — access, rights, banners —
 * goes through this interface, so a covered company follows its host: the same
 * plan, the same state. The covered company keeps a subscription of its own,
 * left pending: it is what the company falls back to, and has to choose a plan
 * on, once the host's plan no longer covers it.
 *
 * A host covers as many companies as its plan's `companies` right allows, its
 * own included; when it allows fewer, the latest covered are the ones left out.
 *
 * @see \Augias\SaasBundle\Tests\Subscription\CoveredSubscriptionProviderTest
 */
#[AsDecorator(decorates: SubscriptionProviderInterface::class)]
final class CoveredSubscriptionProvider implements SubscriptionProviderInterface, ResetInterface
{
    /** @var array<string, Company|null> covered company id => host, for this request */
    private array $hosts = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly SubscriptionProviderInterface $inner,
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanFeatureRepositoryInterface $planFeatures,
        private readonly FeatureConfigRegistry $catalogue,
        private readonly MembershipRepository $memberships,
        private readonly CompanySelector $companySelector,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getSubscriptionFor(SubscribableInterface $subscriber): ?Subscription
    {
        if ($subscriber instanceof Company) {
            $host = $this->hostOf($subscriber);

            if ($host instanceof Company) {
                return $this->inner->getSubscriptionFor($host);
            }
        }

        return $this->inner->getSubscriptionFor($subscriber);
    }

    /**
     * The company whose subscription pays for this one, while its plan still
     * covers it; null for a company on its own subscription.
     */
    public function hostOf(Company $company): ?Company
    {
        $key = $company->getId()->toRfc4122();

        if (array_key_exists($key, $this->hosts)) {
            return $this->hosts[$key];
        }

        $host = null;
        $coverage = $this->entityManager->getRepository(CompanyCoverage::class)->findOneBy(['covered' => $company]);

        if ($coverage instanceof CompanyCoverage) {
            foreach ($this->coveredBy($coverage->getHost()) as $covered) {
                if ($covered->getId()->equals($company->getId())) {
                    $host = $coverage->getHost();

                    break;
                }
            }
        }

        return $this->hosts[$key] = $host;
    }

    /**
     * The companies the host's subscription covers now, oldest first: those
     * its plan has room for, beyond its own.
     *
     * @return list<Company>
     */
    public function coveredBy(Company $host): array
    {
        $covered = array_map(
            static fn (CompanyCoverage $coverage): Company => $coverage->getCovered(),
            $this->coverages($host),
        );

        $subscription = $this->inner->getSubscriptionFor($host);
        if (! $subscription instanceof Subscription) {
            return [];
        }

        $allowance = $this->allowance($subscription->getPlan());

        return $allowance === -1 ? $covered : array_values(array_slice($covered, 0, max(0, $allowance - 1)));
    }

    /**
     * Companies a plan's subscription pays for, its own included; -1 for no limit.
     */
    public function allowance(Plan $plan): int
    {
        $feature = $this->planFeatures->findOneByPlanAndKey($plan, Feature::Companies->value);

        return $feature instanceof PlanFeature
            ? $feature->toFeatureValue()->asInt()
            : $this->catalogue->get(Feature::Companies->value)->toFeatureValue()->asInt();
    }

    /**
     * A company this user owns whose subscription is paid up and has room for
     * one more company; null when there is none.
     */
    public function hostWithRoomFor(User $owner, ?Company $except = null): ?Company
    {
        foreach ($this->ownedBy($owner) as $candidate) {
            if ($except instanceof Company && $candidate->getId()->equals($except->getId())) {
                continue;
            }

            // A covered company does not lend its host's subscription on.
            if ($this->entityManager->getRepository(CompanyCoverage::class)->count(['covered' => $candidate]) > 0) {
                continue;
            }

            $subscription = $this->inner->getSubscriptionFor($candidate);
            if (! $subscription instanceof Subscription || SubscriptionStatus::ACTIVE !== $subscription->getStatus()) {
                continue;
            }

            $allowance = $this->allowance($subscription->getPlan());
            if ($allowance === -1 || count($this->coverages($candidate)) < $allowance - 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The company whose subscription could take this existing one, for its
     * owner to choose to: null when it is covered already, covers others
     * itself, still pays for its own subscription, or the owner has no
     * agency plan with room.
     */
    public function hostOnOffer(Company $company, User $user): ?Company
    {
        if (CompanyRole::Owner !== $this->memberships->findOne($user, $company)?->getRole()) {
            return null;
        }

        $repository = $this->entityManager->getRepository(CompanyCoverage::class);
        if ($repository->count(['covered' => $company]) > 0 || $repository->count(['host' => $company]) > 0) {
            return null;
        }

        // Paying for itself: covering it would bill the owner twice.
        $own = $this->inner->getSubscriptionFor($company);
        if ($own instanceof Subscription && $own->isExternallyBilled() && ! in_array($own->getStatus(), [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true)) {
            return null;
        }

        return $this->hostWithRoomFor($user, $company);
    }

    /**
     * The companies the host covers now that the given plan would no longer
     * cover: the latest, beyond its allowance.
     *
     * @return list<Company>
     */
    public function uncoveredOn(Company $host, Plan $plan): array
    {
        $covered = $this->coveredBy($host);
        $allowance = $this->allowance($plan);

        return $allowance === -1 ? [] : array_values(array_slice($covered, max(0, $allowance - 1)));
    }

    /**
     * Puts the company under the host's subscription.
     */
    public function cover(Company $company, Company $host): void
    {
        $this->entityManager->persist(new CompanyCoverage($company, $host, DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->entityManager->flush();

        unset($this->hosts[$company->getId()->toRfc4122()]);
    }

    public function reset(): void
    {
        $this->hosts = [];
    }

    /**
     * @return list<CompanyCoverage>
     */
    private function coverages(Company $host): array
    {
        /** @var list<CompanyCoverage> */
        return $this->entityManager->getRepository(CompanyCoverage::class)->findBy(['host' => $host], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Read with the company filter off, as FreePlanAllowance does: under it,
     * a user's memberships would answer with the open company only.
     *
     * @return list<Company>
     */
    private function ownedBy(User $user): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('company');
        $selected = $wasEnabled ? $this->companySelector->getCompany() : null;

        if ($wasEnabled) {
            $filters->disable('company');
        }

        try {
            return array_map(static fn (Membership $membership): Company => $membership->getCompany(), $this->memberships->ownedBy($user));
        } finally {
            if ($wasEnabled) {
                // Through the selector: Doctrine re-enables a filter without its parameters.
                if ($selected instanceof Ulid) {
                    $this->companySelector->switchCompany($selected);
                } else {
                    $filters->enable('company');
                }
            }
        }
    }
}
