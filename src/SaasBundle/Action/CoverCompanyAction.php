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

namespace Augias\SaasBundle\Action;

use Augias\CoreBundle\Company\ClosureReason;
use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

/**
 * Puts an existing company under its owner's agency subscription, at the
 * owner's request: one opened before the subscription, or whose own
 * subscription ended. Companies opened afterwards are covered on creation.
 */
final class CoverCompanyAction extends AbstractController
{
    public function __construct(
        private readonly CoveredSubscriptionProvider $coverage,
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyClosure $closure,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('cover_company', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'saas.flash.invalid_token');

            return $this->redirectToRoute('billing_index');
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;
        $user = $this->getUser();

        if (! $company instanceof Company || ! $user instanceof User) {
            return $this->redirectToRoute('billing_index');
        }

        // Asked again here: the page offering it may be out of date.
        $host = $this->coverage->hostOnOffer($company, $user);
        if (! $host instanceof Company) {
            $this->addFlash('error', 'saas.flash.cover_unavailable');

            return $this->redirectToRoute('billing_index');
        }

        $this->coverage->cover($company, $host);

        // Its own subscription had ended: the deletion it set off no longer holds.
        if (ClosureReason::SubscriptionEnded === $company->getClosureReason()) {
            $this->closure->cancel($company);
        }

        $this->addFlash('success', 'saas.flash.company_covered');

        return $this->redirectToRoute('billing_index');
    }
}
