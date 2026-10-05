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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\UserBundle\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Ulid;
use Twig\Attribute\AsTwigFunction;

/**
 * Whether the open company could go under its owner's agency subscription,
 * for the subscription pages to offer it.
 */
final readonly class CoverExtension
{
    public function __construct(
        private CoveredSubscriptionProvider $coverage,
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private Security $security,
    ) {
    }

    /**
     * The company whose subscription would cover the open one; null when
     * there is nothing to offer.
     */
    #[AsTwigFunction('cover_offer')]
    public function coverOffer(): ?Company
    {
        $user = $this->security->getUser();
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;

        if (! $user instanceof User || ! $company instanceof Company) {
            return null;
        }

        return $this->coverage->hostOnOffer($company, $user);
    }
}
