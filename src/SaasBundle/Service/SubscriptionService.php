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

namespace Augias\SaasBundle\Service;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Exception;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\Uid\Ulid;

final readonly class SubscriptionService
{
    public function __construct(
        private ToggleInterface $toggler,
        private SubscriptionProviderInterface $subscriptionProvider,
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
    ) {
    }

    public function isTrialSubscription(): bool
    {
        return $this->unbilledTrial() instanceof Subscription;
    }

    /**
     * The current company's trial when no card is on file yet: the 30 days
     * of Découverte nobody has committed to. Null otherwise, and outside the
     * service.
     */
    public function unbilledTrial(): ?Subscription
    {
        $subscription = $this->current();

        // Subscribed during the trial (billed at its end): not restricted.
        return $subscription?->getStatus() === SubscriptionStatus::TRIAL && ! $subscription->isExternallyBilled() ? $subscription : null;
    }

    /**
     * The current company's subscription; null outside the service.
     */
    public function current(): ?Subscription
    {
        if (! $this->toggler->isActive('saas_enabled')) {
            return null;
        }

        try {
            $companyId = $this->companySelector->getCompany();
            if (! $companyId instanceof Ulid) {
                return null;
            }

            $company = $this->companyRepository->find($companyId);
            if (! $company instanceof Company) {
                return null;
            }

            return $this->subscriptionProvider->getSubscriptionFor($company);
        } catch (Exception) {
            // Handle cases where subscription tables don't exist (self-hosted, dev environments)
            return null;
        }
    }
}
