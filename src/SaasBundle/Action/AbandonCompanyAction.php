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

use Augias\CoreBundle\Company\CompanyPurgeContext;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Takes back a company whose subscription never started.
 *
 * A company exists from the moment its form is sent, before any plan is
 * chosen: that is what a subscription and a checkout hang off. When no plan
 * follows — a checkout that fails, a second free company refused (test
 * instance, 30/09/2026) — it stayed in the company list for good, pending.
 * Its owner can now remove it, and only while nothing was ever paid for.
 *
 * @see \Augias\SaasBundle\Tests\Action\AbandonCompanyActionTest
 */
final class AbandonCompanyAction extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly SubscriptionProviderInterface $subscriptionProvider,
        private readonly CompanyPurgeContext $purgeContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('abandon_company', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'saas.flash.invalid_token');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $user = $this->getUser();
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;

        if (! $user instanceof User || ! $company instanceof Company || ! self::canBeAbandoned($company, $user, $this->subscriptionProvider->getSubscriptionFor($company))) {
            $this->addFlash('error', 'saas.flash.abandon_refused');

            return $this->redirectToRoute('saas_subscription_plans');
        }

        $name = (string) $company->getName();
        $this->purgeContext->during($company, fn () => $this->companyRepository->deleteCompany($company->getId()));
        $this->companySelector->reset();
        $request->getSession()->remove('company');

        $this->addFlash('success', $this->translator->trans('saas.flash.company_abandoned', ['%name%' => $name]));

        // The company picker sends on to the one company left, or to creating
        // one when none is.
        return $this->redirectToRoute('_select_company');
    }

    /**
     * Only a company still waiting for its first plan, and only by its owner:
     * anything that was ever active has data and history, and closes by the
     * ordinary closure, with its notice period.
     */
    public static function canBeAbandoned(Company $company, User $user, ?Subscription $subscription): bool
    {
        if (! $subscription instanceof Subscription
            || SubscriptionStatus::PENDING !== $subscription->getStatus()
            || $subscription->isExternallyBilled()
        ) {
            return false;
        }

        return CompanyRole::Owner === $user->getMembership($company)?->getRole();
    }
}
