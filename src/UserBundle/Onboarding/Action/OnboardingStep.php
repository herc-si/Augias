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

use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Form\Type\BicType;
use Augias\CoreBundle\Form\Type\IbanType;
use Augias\CoreBundle\Form\Type\ImageUploadType;
use Augias\ElectronicInvoicingBundle\Action\Setup\Connect;
use Augias\ElectronicInvoicingBundle\Form\Type\CompanyIdentityType;
use Augias\ElectronicInvoicingBundle\Onboarding\CompanyIdentity;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Augias\ElectronicInvoicingBundle\Onboarding\PlatformConnectionGate;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Listener\RequireAuthenticatorAppListener;
use Augias\UserBundle\Onboarding\DTO\OnboardingData;
use Augias\UserBundle\Onboarding\Form\Step\ClientSetupStep;
use Augias\UserBundle\Onboarding\Form\Step\InvoiceSetupStep;
use Augias\UserBundle\Onboarding\Journey\JourneyStep;
use Augias\UserBundle\Onboarding\Journey\SetupJourney;
use Augias\UserBundle\Onboarding\Journey\SetupStep;
use Augias\UserBundle\Onboarding\Manager\OnboardingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function array_search;
use function assert;
use function count;
use function is_string;
use function trim;

/**
 * One step of the setup journey, once the company exists: who it is, its
 * documents, the second factor, electronic invoicing, then a first client
 * and invoice (SetupJourney). Each step saved leads to the next one left.
 *
 * @see \Augias\UserBundle\Tests\Functional\SetupJourneyTest
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class OnboardingStep extends AbstractController
{
    public function __construct(
        private readonly SetupJourney $journey,
        private readonly OnboardingManager $onboardingManager,
        private readonly ElectronicInvoicingOnboarding $einvoicing,
        private readonly PlatformConnectionGate $platformGate,
        private readonly SystemConfig $systemConfig,
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(Request $request, string $step): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $step = SetupStep::tryFrom($step);

        if (! $step instanceof SetupStep || $step === SetupStep::Company || ! $this->journey->canShow($user, $step)) {
            return $this->redirectToRoute('_onboarding');
        }

        $current = $this->stepOf($user, $step);

        if ($request->isMethod('POST') && $request->request->has('skip')) {
            if ($this->isCsrfTokenValid('onboarding_skip', (string) $request->request->get('_token')) && $current->skippable) {
                $this->journey->skip($user, $step);
            }

            return $this->carryOn($user);
        }

        $view = match ($step) {
            SetupStep::Identity => $this->identity($request),
            SetupStep::Documents => $this->documents($request, $user),
            SetupStep::Security => $this->security($request, $user),
            SetupStep::ElectronicInvoicing => $this->electronicInvoicing($request),
            SetupStep::FirstInvoice => $this->firstInvoice($request, $user),
        };

        if ($view instanceof Response) {
            return $view;
        }

        $steps = $this->journey->steps($user);

        return $this->render('@AugiasUser/Onboarding/onboarding.html.twig', $view + [
            'currentStep' => $step->value,
            'step' => $current,
            'steps' => $steps,
            'position' => (int) array_search($step, SetupStep::cases(), true) + 1,
            'count' => count($steps),
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function identity(Request $request): array | Response
    {
        $form = $this->identityForm($this->einvoicing->identity());
        $form->handleRequest($request);
        $lookedUp = null;

        if ($form->isSubmitted()) {
            $lookUp = $form->get(CompanyIdentityType::LOOK_UP);
            assert($lookUp instanceof ClickableInterface);
            $typed = $form->getData();
            assert($typed instanceof CompanyIdentity);

            if ($lookUp->isClicked()) {
                $filled = $this->einvoicing->lookUp($typed);
                // Found even when the name is not public (« diffusion partielle »).
                $lookedUp = $filled->siren !== null && $this->einvoicing->registryHas($filled->siren);
                // A new form over the filled data, without the errors of
                // what was not typed yet.
                $form = $this->identityForm($filled);
            } elseif ($form->isValid()) {
                $this->einvoicing->saveIdentity($typed);

                $user = $this->getUser();
                assert($user instanceof User);

                return $this->carryOn($user);
            }
        }

        return ['form' => $form->createView(), 'lookedUp' => $lookedUp];
    }

    /**
     * @return FormInterface<CompanyIdentity>
     */
    private function identityForm(CompanyIdentity $identity): FormInterface
    {
        return $this->createForm(CompanyIdentityType::class, $identity, [
            'action' => $this->generateUrl('_onboarding_step', ['step' => SetupStep::Identity->value]),
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function documents(Request $request, User $user): array | Response
    {
        $form = $this->createFormBuilder([
            'bankName' => $this->systemConfig->get(CompanyBankDetails::BANK_NAME),
            'iban' => $this->systemConfig->get(CompanyBankDetails::IBAN),
            'bic' => $this->systemConfig->get(CompanyBankDetails::BIC),
        ])
            ->add('logo', ImageUploadType::class, ['required' => false, 'label' => 'onboarding.documents.fields.logo'])
            ->add('bankName', TextType::class, ['required' => false, 'label' => 'onboarding.documents.fields.bank_name', 'attr' => ['maxlength' => 100]])
            ->add('iban', IbanType::class, ['required' => false, 'label' => 'onboarding.documents.fields.iban'])
            ->add('bic', BicType::class, ['required' => false, 'label' => 'onboarding.documents.fields.bic'])
            ->add('save', SubmitType::class, ['label' => 'onboarding.navigation.continue'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{logo: ?string, bankName: ?string, iban: ?string, bic: ?string} $data */
            $data = $form->getData();

            if (is_string($data['logo']) && $data['logo'] !== '') {
                $this->systemConfig->set('system/company/logo', $data['logo']);
            }

            $this->systemConfig->set(CompanyBankDetails::BANK_NAME, trim((string) $data['bankName']));
            $this->systemConfig->set(CompanyBankDetails::IBAN, (string) $data['iban']);
            $this->systemConfig->set(CompanyBankDetails::BIC, (string) $data['bic']);

            // Saved without an IBAN: the person chose to go on without one.
            if (! $this->stepOf($user, SetupStep::Documents)->done) {
                $this->journey->skip($user, SetupStep::Documents);
            }

            return $this->carryOn($user);
        }

        return ['form' => $form->createView()];
    }

    /**
     * Set up on the second factor's own page: it offers to come back here
     * once the app is there (RequireAuthenticatorAppListener).
     *
     * @return array<string, mixed>|Response
     */
    private function security(Request $request, User $user): array | Response
    {
        if ($request->isMethod('POST')) {
            if ($this->stepOf($user, SetupStep::Security)->done) {
                return $this->carryOn($user);
            }

            $request->getSession()->set(RequireAuthenticatorAppListener::RESUME_SESSION_KEY, $this->generateUrl('_onboarding'));

            return $this->redirectToRoute('_2fa_list');
        }

        return [];
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function electronicInvoicing(Request $request): array | Response
    {
        $progress = $this->einvoicing->progress();

        if ($request->isMethod('POST') && $progress->connected) {
            $user = $this->getUser();
            assert($user instanceof User);

            return $this->carryOn($user);
        }

        return [
            'progress' => $progress,
            'waitsForCardUntil' => $this->platformGate->waitsForCardUntil(),
            'connectReturn' => Connect::RETURN_TO_ONBOARDING,
        ];
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function firstInvoice(Request $request, User $user): array | Response
    {
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->entityManager->find(Company::class, $companyId) : null;
        assert($company instanceof Company);

        $data = new OnboardingData();
        $form = $this->createFormBuilder($data, [
            // The invoice is optional; the client is not, on this button.
            // The amount starts at 0: only a description, or an amount, says
            // an invoice is wanted.
            'validation_groups' => static fn (FormInterface $form): array => self::wantsInvoice($data) ? ['client', 'invoice'] : ['client'],
        ])
            ->add('client', ClientSetupStep::class, ['inherit_data' => true, 'label' => false])
            ->add('invoice', InvoiceSetupStep::class, ['inherit_data' => true, 'label' => false, 'currency' => $company->currency ?? 'EUR'])
            ->add('save', SubmitType::class, ['label' => 'onboarding.first_invoice.save'])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (! self::wantsInvoice($data)) {
                $data->invoiceDescription = null;
                $data->invoiceAmount = null;
            }

            $invoice = $this->onboardingManager->createFirstInvoice($company, $data);
            $this->journey->finish($user);

            if ($invoice instanceof Invoice) {
                $this->addFlash('success', 'onboarding.flash.invoice_created');

                return $this->redirectToRoute('_invoices_view', ['id' => $invoice->getId()]);
            }

            $this->addFlash('success', $this->finishedMessage($user));

            return $this->redirectToRoute('_dashboard');
        }

        return ['form' => $form->createView()];
    }

    /**
     * On to the first step left, or out of the journey once none is.
     */
    private function carryOn(User $user): Response
    {
        $next = $this->journey->current($user);

        if (! $next instanceof JourneyStep) {
            $this->journey->finish($user);
            $this->addFlash('success', $this->finishedMessage($user));

            return $this->redirectToRoute('_dashboard');
        }

        return $this->redirectToRoute('_onboarding_step', ['step' => $next->step->value]);
    }

    /**
     * "All set" only when it is: steps left for later are said to wait in
     * the dashboard's checklist.
     */
    private function finishedMessage(User $user): string
    {
        foreach ($this->journey->steps($user) as $journeyStep) {
            if ($journeyStep->skipped && ! $journeyStep->done) {
                return 'onboarding.flash.journey_done_later';
            }
        }

        return 'onboarding.flash.onboarding_complete';
    }

    private static function wantsInvoice(OnboardingData $data): bool
    {
        $amount = trim((string) $data->invoiceAmount);

        return trim((string) $data->invoiceDescription) !== '' || ($amount !== '' && (float) $amount !== 0.0);
    }

    private function stepOf(User $user, SetupStep $step): JourneyStep
    {
        foreach ($this->journey->steps($user) as $journeyStep) {
            if ($journeyStep->step === $step) {
                return $journeyStep;
            }
        }

        throw new \LogicException('Every step is part of the journey.');
    }
}
