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

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Onboarding\DTO\OnboardingData;
use Augias\UserBundle\Onboarding\Form\Type\OnboardingType;
use Augias\UserBundle\Onboarding\Manager\OnboardingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function assert;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class Onboarding extends AbstractController
{
    public function __construct(
        private readonly OnboardingManager $onboardingManager,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // If already completed, redirect to dashboard — which is where "Go to
        // Dashboard" on the last page leads, the company having been created
        // on arrival there.
        if ($this->onboardingManager->isOnboardingComplete($user)) {
            if ($request->isMethod('POST')) {
                $this->addFlash('success', 'onboarding.flash.onboarding_complete');
            }

            return $this->redirectToRoute('_dashboard');
        }

        // Initialize onboarding if not started
        $currentStep = $this->onboardingManager->getCurrentStep($user);

        if (! $currentStep) {
            $this->onboardingManager->startOnboarding($user);
        }

        // Create and handle form
        $form = $this->createForm(OnboardingType::class, new OnboardingData())
            ->handleRequest($request);

        assert($form instanceof FormFlowInterface);

        // Check if we're on the complete step after invoice submission (auto-complete with invoice data)

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->getCursor()->getCurrentStep() === 'invoice') {
                $formData = $form->getData();
                assert($formData instanceof OnboardingData);

                // If we have invoice data, complete onboarding immediately and redirect to invoice
                if ($formData->invoiceDescription && $formData->invoiceAmount) {
                    $invoice = $this->onboardingManager->completeOnboarding($user, $formData);
                    $form->reset();

                    if ($invoice instanceof Invoice) {
                        $this->addFlash('success', 'onboarding.flash.invoice_created');
                        return $this->redirectToRoute('_invoices_view', ['id' => $invoice->getId()]);
                    }
                }
            } elseif ($form->isFinished()) {
                $formData = $form->getData();
                assert($formData instanceof OnboardingData);

                // Save all data and get created invoice
                $invoice = $this->onboardingManager->completeOnboarding($user, $formData);

                // Clear form data from session
                $form->reset();

                // If an invoice was created, redirect to invoice detail page
                if ($invoice instanceof Invoice) {
                    $this->addFlash('success', 'onboarding.flash.invoice_created');
                    return $this->redirectToRoute('_invoices_view', ['id' => $invoice->getId()]);
                }

                // Otherwise, redirect to dashboard
                $this->addFlash('success', 'onboarding.flash.onboarding_complete');
                return $this->redirectToRoute('_dashboard');
            } else {
                $this->onboardingManager->setCurrentStep($user, $form->getCursor()->getCurrentStep());
            }
        }

        // Built before the check below: a Skip only moves the cursor when the
        // step form is built.
        $stepForm = $form->getStepForm();
        $cursor = $form->getCursor();

        // The last page offers links out — add a client, set up payments — so
        // it must be true when it says "all set". The company used to be
        // created only by its Finish button: a link followed first left an
        // account with no company, sent to create one (test instance,
        // 29/09/2026).
        $completedOnArrival = $form->isSubmitted() && $cursor->isLastStep();

        if ($completedOnArrival) {
            $arrived = $form->getData();
            assert($arrived instanceof OnboardingData);

            $this->onboardingManager->completeOnboarding($user, $arrived);
        }

        $formData = $form->getData();
        assert($formData instanceof OnboardingData);

        // Render current step
        $response = $this->render('@AugiasUser/Onboarding/onboarding.html.twig', [
            'form' => $stepForm,
            'currentStep' => $cursor->getCurrentStep(),
            'progress' => $this->calculateProgress($form),
            'hasClient' => $formData->clientName !== null && $formData->clientName !== '',
        ]);

        // Rendered first: the page still reads the flow's cursor.
        if ($completedOnArrival) {
            $form->reset();
        }

        return $response;
    }

    /**
     * Calculate progress percentage
     */
    private function calculateProgress(FormFlowInterface $form): int
    {
        $cursor = $form->getCursor();
        $totalSteps = count($cursor->getSteps());
        $currentPosition = $cursor->getStepIndex();

        return (int) (($currentPosition / $totalSteps) * 100);
    }
}
