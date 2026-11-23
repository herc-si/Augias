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

namespace Augias\UserBundle\Onboarding\Journey;

use const JSON_THROW_ON_ERROR;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Entity\UserSetting;
use Augias\UserBundle\Enum\UserSettingType;
use Augias\UserBundle\Repository\UserSettingRepository;
use Augias\UserBundle\Security\AuthenticatorAppPolicy;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;
use function array_filter;
use function array_search;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * The setup journey a new account goes through, from creating its company to
 * its first invoice, so that nothing is left half done (Rémi, 23/11/2026):
 * signing up used to ask only for a name, leave the second factor to the
 * first page after, and never ask for the SIREN, the address or the VAT
 * regime outside the electronic invoicing setup.
 *
 * Each step says whether it is done from the data itself — a SIREN saved
 * from the settings counts as much as one typed here — and the journey
 * resumes at the first step neither done nor left for later. A step that
 * cannot be left (the company, who it is, the second factor on the service)
 * has to be done before the next one is reached.
 *
 * @see \Augias\UserBundle\Tests\Functional\SetupJourneyTest
 */
final readonly class SetupJourney
{
    public function __construct(
        private UserSettingRepository $settings,
        private EntityManagerInterface $entityManager,
        private CompanySelector $companySelector,
        private ElectronicInvoicingOnboarding $einvoicing,
        private CompanyBankDetails $bankDetails,
        private AuthenticatorAppPolicy $authenticatorApp,
    ) {
    }

    /**
     * @return list<JourneyStep>
     */
    public function steps(User $user): array
    {
        $skipped = $this->skipped($user);
        $hasCompany = $this->companySelector->getCompany() instanceof Ulid && ! $user->getCompanies()->isEmpty();
        $progress = $hasCompany ? $this->einvoicing->progress() : null;

        $steps = [];
        foreach (SetupStep::cases() as $step) {
            $done = match ($step) {
                SetupStep::Company => $hasCompany,
                SetupStep::Identity => $progress->identityDone ?? false,
                SetupStep::Documents => $hasCompany && $this->bankDetails->get() !== null,
                SetupStep::Security => $user->isTotpAuthenticationEnabled(),
                SetupStep::ElectronicInvoicing => $progress->connected ?? false,
                SetupStep::FirstInvoice => $hasCompany && $this->entityManager->getRepository(Client::class)->count([]) > 0,
            };

            $skippable = match ($step) {
                SetupStep::Company, SetupStep::Identity => false,
                SetupStep::Security => ! $this->authenticatorApp->isRequired(),
                default => true,
            };

            $steps[] = new JourneyStep($step, $done, $skippable && in_array($step->value, $skipped, true), $skippable);
        }

        return $steps;
    }

    /**
     * Where the journey stands: the first step nothing has settled yet; null
     * once each is done or left for later.
     */
    public function current(User $user): ?JourneyStep
    {
        foreach ($this->steps($user) as $step) {
            if (! $step->isSettled()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Whether $step may be shown: one already settled, to go back to, or the
     * current one. A later one would jump past a step that cannot be left.
     */
    public function canShow(User $user, SetupStep $step): bool
    {
        foreach ($this->steps($user) as $journeyStep) {
            if ($journeyStep->step === $step) {
                return true;
            }

            if (! $journeyStep->isSettled()) {
                return false;
            }
        }

        return false;
    }

    /**
     * The step after $step, or null when it was the last.
     */
    public function after(SetupStep $step): ?SetupStep
    {
        $cases = SetupStep::cases();
        $index = array_search($step, $cases, true);

        return $cases[$index + 1] ?? null;
    }

    public function skip(User $user, SetupStep $step): void
    {
        $skipped = $this->skipped($user);
        if (! in_array($step->value, $skipped, true)) {
            $skipped[] = $step->value;
        }

        $this->settings->saveSetting($user, UserSettingType::OnboardingSkipped, json_encode($skipped, JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }

    public function start(User $user): void
    {
        if ($this->isStarted($user)) {
            return;
        }

        $this->settings->saveSetting($user, UserSettingType::OnboardingStartedAt, CarbonImmutable::now()->format('Y-m-d H:i:s'));
        $this->entityManager->flush();
    }

    public function isStarted(User $user): bool
    {
        return $this->setting($user, UserSettingType::OnboardingStartedAt) !== null;
    }

    public function isFinished(User $user): bool
    {
        return in_array($this->setting($user, UserSettingType::OnboardComplete), ['true', 'dismissed'], true);
    }

    /**
     * Under way: begun, and not finished yet.
     */
    public function isUnderWay(User $user): bool
    {
        return $this->isStarted($user) && ! $this->isFinished($user);
    }

    public function finish(User $user): void
    {
        $this->settings->saveSetting($user, UserSettingType::OnboardComplete, 'true');
        $this->settings->saveSetting($user, UserSettingType::OnboardingCompletedAt, CarbonImmutable::now()->format('Y-m-d H:i:s'));
        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    private function skipped(User $user): array
    {
        $value = $this->setting($user, UserSettingType::OnboardingSkipped);
        $skipped = $value !== null ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];

        return is_array($skipped) ? array_values(array_filter($skipped, is_string(...))) : [];
    }

    private function setting(User $user, UserSettingType $type): ?string
    {
        $setting = $this->settings->findOneBy(['user' => $user, 'key' => $type]);

        return $setting instanceof UserSetting ? $setting->getValue() : null;
    }
}
