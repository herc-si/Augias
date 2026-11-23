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

namespace Augias\UserBundle\Onboarding\Action;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\MoneyBundle\Currency\CurrencyPolicy;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Onboarding\DTO\OnboardingData;
use Augias\UserBundle\Onboarding\Form\Step\CompanySetupStep;
use Augias\UserBundle\Onboarding\Journey\JourneyStep;
use Augias\UserBundle\Onboarding\Journey\SetupJourney;
use Augias\UserBundle\Onboarding\Manager\OnboardingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function assert;

/**
 * Where the setup journey starts, and where it is resumed from: the company
 * is created here, then each step follows (OnboardingStep). Reached outside
 * any company — the new account has none yet.
 *
 * @see \Augias\UserBundle\Tests\Functional\SetupJourneyTest
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class Onboarding extends AbstractController
{
    public function __construct(
        private readonly OnboardingManager $onboardingManager,
        private readonly SetupJourney $journey,
        private readonly CompanySelector $companySelector,
        private readonly CurrencyPolicy $currencies,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        if (! $user->getCompanies()->isEmpty()) {
            return $this->resume($user);
        }

        $this->journey->start($user);

        $data = new OnboardingData();
        $data->companyCurrency = $this->currencies->defaultCode();

        $form = $this->createFormBuilder($data, ['validation_groups' => ['company']])
            ->add('company', CompanySetupStep::class, ['inherit_data' => true, 'label' => false])
            ->add('next', SubmitType::class, ['label' => 'onboarding.navigation.continue'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $company = $this->onboardingManager->createCompanyFor($user, $data);

            $request->getSession()->set('company', $company->getId());
            $this->companySelector->switchCompany($company->getId());

            return $this->resume($user);
        }

        return $this->render('@AugiasUser/Onboarding/onboarding.html.twig', [
            'form' => $form,
            'currentStep' => 'company',
            'steps' => [],
            'position' => 1,
            'count' => 6,
        ]);
    }

    private function resume(User $user): Response
    {
        if ($this->journey->isFinished($user)) {
            return $this->redirectToRoute('_dashboard');
        }

        $current = $this->journey->current($user);

        if (! $current instanceof JourneyStep) {
            $this->journey->finish($user);
            $this->addFlash('success', 'onboarding.flash.onboarding_complete');

            return $this->redirectToRoute('_dashboard');
        }

        return $this->redirectToRoute('_onboarding_step', ['step' => $current->step->value]);
    }
}
