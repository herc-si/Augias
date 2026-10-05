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
        // Return false if SaaS is not enabled
        if (! $this->toggler->isActive('saas_enabled')) {
            return false;
        }

        try {
            $companyId = $this->companySelector->getCompany();
            if (! $companyId instanceof Ulid) {
                return false;
            }

            $company = $this->companyRepository->find($companyId);
            if (! $company instanceof Company) {
                return false;
            }

            $subscription = $this->subscriptionProvider->getSubscriptionFor($company);

            // Subscribed during the trial (billed at its end): not restricted.
            return $subscription?->getStatus() === SubscriptionStatus::TRIAL && ! $subscription->isExternallyBilled();
        } catch (Exception) {
            // Handle cases where subscription tables don't exist (self-hosted, dev environments)
            return false;
        }
    }
}
