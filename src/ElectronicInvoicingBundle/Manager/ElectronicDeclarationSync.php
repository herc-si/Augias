<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicDeclaration;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoicingProblem;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicDeclarationsInterface;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicDeclarationRepository;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Copies the declarations the platform sent the tax administration — what
 * it built from Augias's e-reporting — and tells the company when one is
 * rejected, once.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Manager\ElectronicDeclarationSyncTest
 */
final readonly class ElectronicDeclarationSync
{
    public function __construct(
        private ElectronicInvoiceProviderSettingRepository $settings,
        private ElectronicInvoiceProviderRegistry $providers,
        private ElectronicDeclarationRepository $declarations,
        private EntityManagerInterface $entityManager,
        private ElectronicInvoicingAlerts $alerts,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return int how many declarations are new or changed, 0 when the
     *             platform does not show them
     *
     * @throws RuntimeException when the platform could not be asked
     */
    public function refresh(Company $company): int
    {
        $setting = $this->settings->findActiveForCompany($company->getId());
        $provider = $setting instanceof ElectronicInvoiceProviderSetting ? $this->providers->get($setting->getProvider()) : null;

        if (! $provider instanceof ElectronicDeclarationsInterface || ! $setting instanceof ElectronicInvoiceProviderSetting) {
            return 0;
        }

        $changed = 0;

        foreach ($provider->declarations($setting->getSettings()) as $declared) {
            $declaration = $this->declarations->findOne($company->getId(), $setting->getProvider(), $declared->externalId);

            if (! $declaration instanceof ElectronicDeclaration) {
                $declaration = new ElectronicDeclaration($setting->getProvider(), $declared->externalId, $declared->kind, $declared->periodStart, $declared->periodEnd);
                $declaration->setCompany($company);
                $this->entityManager->persist($declaration);
                ++$changed;
            }

            $wasRejected = $declaration->isRejected();

            if ($declaration->updateStatus($declared->status, $declared->statusAt)) {
                ++$changed;

                if ($declaration->isRejected() && ! $wasRejected) {
                    $this->alerts->raise($company, ElectronicInvoicingProblem::DeclarationRejected, detail: $this->translator->trans('einvoicing.ereporting.declaration.period', [
                        '%kind%' => $this->translator->trans('einvoicing.ereporting.kind.' . $declaration->getKind()),
                        '%start%' => $declaration->getPeriodStart()->format('d/m/Y'),
                        '%end%' => $declaration->getPeriodEnd()->format('d/m/Y'),
                    ]));
                }
            }
        }

        $this->entityManager->flush();

        return $changed;
    }
}
