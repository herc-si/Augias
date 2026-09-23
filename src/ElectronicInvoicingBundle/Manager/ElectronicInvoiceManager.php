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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceProviderSettingRepository;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central place for "can this invoice be sent electronically, and doing so" —
 * shared by the explicit "Send electronic invoice" action and the automatic
 * trigger fired when an invoice is published (see InvoiceBundle\Action\Transition\Send),
 * so eligibility and submission bookkeeping stay in one place.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Manager\ElectronicInvoiceManagerTest
 */
final readonly class ElectronicInvoiceManager implements ElectronicInvoiceManagerInterface
{
    /**
     * Why an invoice of nothing but disbursements was not sent. The
     * disbursements go out on a note of their own, which is not an invoice
     * and stays outside e-invoicing — so such an invoice has no line left to
     * transmit, and an empty e-invoice is not one.
     */
    public const string ONLY_DISBURSEMENTS = 'einvoicing.send.only_disbursements';

    public function __construct(
        private SystemConfig $systemConfig,
        private ElectronicInvoiceProviderRegistry $registry,
        private ElectronicInvoiceProviderSettingRepository $settingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function isEligible(Invoice $invoice): bool
    {
        if ($this->systemConfig->get(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH) !== '1') {
            return false;
        }

        if (! $this->registry->hasActiveProvider()) {
            return false;
        }

        return $this->clientHasSiret($invoice);
    }

    public function send(Invoice $invoice): ElectronicInvoiceSubmission
    {
        $activeSetting = $this->settingRepository->findActive();

        if ($invoice->hasOnlyDisbursements()) {
            $submission = new ElectronicInvoiceSubmission();
            $submission->setInvoice($invoice)
                ->setProvider($activeSetting instanceof ElectronicInvoiceProviderSetting ? $activeSetting->getProvider() : '')
                ->setSuccess(false)
                ->setMessage(self::ONLY_DISBURSEMENTS);

            $this->entityManager->persist($submission);
            $this->entityManager->flush();

            return $submission;
        }

        $result = $this->registry->send($invoice);

        $submission = new ElectronicInvoiceSubmission();
        $submission->setInvoice($invoice)
            ->setProvider($activeSetting instanceof ElectronicInvoiceProviderSetting ? $activeSetting->getProvider() : '')
            ->setSuccess($result->success)
            ->setExternalReference($result->externalReference)
            ->setMessage($result->message);

        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        return $submission;
    }

    private function clientHasSiret(Invoice $invoice): bool
    {
        $client = $invoice->getClient();

        if ($client === null) {
            return false;
        }

        foreach ($client->getTaxIdentifiers() as $identifier) {
            if ($identifier->getLabel() === 'SIRET' && $identifier->getValue() !== null && $identifier->getValue() !== '') {
                return true;
            }
        }

        return false;
    }
}
