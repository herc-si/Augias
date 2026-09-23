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

namespace Augias\ElectronicInvoicingBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Response\FlashResponse;
use Augias\ElectronicInvoicingBundle\Action\SendElectronicInvoice;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceSubmissionRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(SendElectronicInvoice::class)]
#[CoversClass(ElectronicInvoiceProviderRegistry::class)]
#[CoversClass(ElectronicInvoiceProviderSetting::class)]
#[CoversClass(ElectronicInvoiceManager::class)]
final class ElectronicInvoicingFlowTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testSendingElectronicInvoiceWithActiveTestProviderCreatesSuccessfulSubmission(): void
    {
        $invoice = $this->createInvoiceForClientWithSiret();
        $this->configureActiveTestProvider();

        $this->sendElectronicInvoice($invoice);

        $submissions = self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class)->findAll();

        self::assertCount(1, $submissions);
        self::assertTrue($submissions[0]->isSuccess());
        self::assertSame('test_provider', $submissions[0]->getProvider());
        self::assertNotNull($submissions[0]->getExternalReference());
        self::assertStringStartsWith('E2E-', $submissions[0]->getExternalReference());
        self::assertSame($invoice->getId(), $submissions[0]->getInvoice()->getId());
    }

    public function testSendingElectronicInvoiceWithSimulatedFailureCreatesFailedSubmission(): void
    {
        $invoice = $this->createInvoiceForClientWithSiret();
        $this->configureActiveTestProvider(['reference_prefix' => 'E2E', 'simulate_failure' => true]);

        $this->sendElectronicInvoice($invoice);

        $submissions = self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class)->findAll();

        self::assertCount(1, $submissions);
        self::assertFalse($submissions[0]->isSuccess());
        self::assertNull($submissions[0]->getExternalReference());
        self::assertSame('einvoicing.provider.test.simulated_failure', $submissions[0]->getMessage());
    }

    public function testSendingElectronicInvoiceWithoutActiveProviderCreatesNoSubmission(): void
    {
        $invoice = $this->createInvoiceForClientWithSiret();

        $this->sendElectronicInvoice($invoice);

        $submissions = self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class)->findAll();

        self::assertCount(0, $submissions);
    }

    /**
     * Fees and a disbursement on one invoice: the disbursement goes out on
     * its note, the e-invoice carries the fees alone — valid EN 16931, where
     * a "not subject to VAT" breakdown beside the fees would not be (BR-O-11).
     */
    public function testAnInvoiceWithFeesAndDisbursementsIsSentWithTheFees(): void
    {
        $invoice = $this->withLines($this->createInvoiceForClientWithSiret(), [false, true]);
        $this->configureActiveTestProvider();

        $this->sendElectronicInvoice($invoice);

        $submissions = self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class)->findAll();

        self::assertCount(1, $submissions);
        self::assertTrue($submissions[0]->isSuccess());
    }

    /**
     * Nothing but disbursements leaves nothing to transmit: they are all on
     * the note, which is not an invoice. Refused with the reason, and the
     * provider is never called.
     */
    public function testAnInvoiceOfDisbursementsAloneIsNotSent(): void
    {
        $invoice = $this->withLines($this->createInvoiceForClientWithSiret(), [true]);
        $this->configureActiveTestProvider();

        $response = self::getContainer()->get(SendElectronicInvoice::class)(Request::createFromGlobals(), $invoice);

        $submissions = self::getContainer()->get(ElectronicInvoiceSubmissionRepository::class)->findAll();

        self::assertCount(1, $submissions);
        self::assertFalse($submissions[0]->isSuccess());
        self::assertNull($submissions[0]->getExternalReference(), 'Nothing reached the provider.');
        self::assertSame(ElectronicInvoiceManager::ONLY_DISBURSEMENTS, $submissions[0]->getMessage());

        self::assertInstanceOf(FlashResponse::class, $response);
        self::assertSame([FlashResponse::FLASH_ERROR => ElectronicInvoiceManager::ONLY_DISBURSEMENTS], iterator_to_array($response->getFlash()));
    }

    public function testTwoProviderSettingsWithTheSameNameForACompanyAreRejectedByTheValidator(): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $first = new ElectronicInvoiceProviderSetting();
        $first->setCompany($this->company)
            ->setName('My Provider')
            ->setProvider('test_provider')
            ->setSettings(['reference_prefix' => 'A']);
        $entityManager->persist($first);
        $entityManager->flush();

        $second = new ElectronicInvoiceProviderSetting();
        $second->setCompany($this->company)
            ->setName('My Provider')
            ->setProvider('test_provider')
            ->setSettings(['reference_prefix' => 'B']);

        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($second);

        self::assertGreaterThan(0, count($violations));
        self::assertSame('einvoicing.constraint.provider_setting.unique_name', $violations[0]->getMessageTemplate());
    }

    private function createInvoiceForClientWithSiret(): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => '12345678900012',
        ]);

        return InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => InvoiceStatus::Pending,
        ]);
    }

    /**
     * Replaces the invoice's lines with one per flag, true for a disbursement.
     *
     * @param list<bool> $disbursements
     */
    private function withLines(Invoice $invoice, array $disbursements): Invoice
    {
        foreach ($invoice->getLines()->toArray() as $line) {
            $invoice->removeLine($line);
        }

        foreach ($disbursements as $disbursement) {
            $invoice->addLine(new Line()->setDescription('Line')->setPrice(10_000)->setQty(1)->setDisbursement($disbursement));
        }

        self::getContainer()->get('doctrine')->getManager()->flush();

        return $invoice;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configureActiveTestProvider(array $settings = ['reference_prefix' => 'E2E']): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');

        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $providerSetting = new ElectronicInvoiceProviderSetting();
        $providerSetting->setCompany($this->company)
            ->setName('Test Provider')
            ->setProvider('test_provider')
            ->setSettings($settings)
            ->setActive(true);

        $entityManager->persist($providerSetting);
        $entityManager->flush();
    }

    private function sendElectronicInvoice(Invoice $invoice): void
    {
        $action = self::getContainer()->get(SendElectronicInvoice::class);
        $action(Request::createFromGlobals(), $invoice);
    }
}
