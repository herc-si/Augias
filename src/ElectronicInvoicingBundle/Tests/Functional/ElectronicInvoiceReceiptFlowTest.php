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

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManager;
use Augias\ElectronicInvoicingBundle\Provider\ElectronicInvoiceProviderRegistry;
use Augias\ElectronicInvoicingBundle\Repository\ElectronicInvoiceReceiptRepository;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use function file_get_contents;

#[CoversClass(ElectronicInvoiceReceiptManager::class)]
#[CoversClass(ElectronicInvoiceReceiptRepository::class)]
#[CoversClass(ElectronicInvoiceProviderRegistry::class)]
final class ElectronicInvoiceReceiptFlowTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    protected function tearDown(): void
    {
        // The manager writes real files with the attachments — clean up after
        // each test so runs don't accumulate stray fixtures on disk.
        new Filesystem()->remove(self::attachmentsDir() . '/einvoicing/incoming/' . $this->company->getId()->toBase58());

        parent::tearDown();
    }

    public function testIsReceivingEnabledIsFalseWithoutAnActiveProvider(): void
    {
        $manager = self::getContainer()->get(ElectronicInvoiceReceiptManager::class);

        self::assertFalse($manager->isReceivingEnabled($this->company));
        self::assertSame([], $manager->importNew($this->company));
    }

    public function testImportingWithActiveTestProviderCreatesAReceiptAndItsDocument(): void
    {
        $this->configureActiveTestProvider();

        $manager = self::getContainer()->get(ElectronicInvoiceReceiptManager::class);

        self::assertTrue($manager->isReceivingEnabled($this->company));

        $imported = $manager->importNew($this->company);

        self::assertCount(1, $imported);

        $receipt = $imported[0];
        self::assertSame('test_provider', $receipt->getProvider());
        self::assertSame('DEMO-0001', $receipt->getInvoiceNumber());
        self::assertSame('Demo Supplier Inc.', $receipt->getSellerName());
        $amount = $receipt->getAmount();
        self::assertNotNull($amount);
        self::assertSame('12000', $amount->getAmount());
        self::assertSame('EUR', $receipt->getCurrencyCode());
        self::assertTrue($receipt->hasDocument());

        // With the attachments, on the volume every container shares.
        $absolutePath = self::attachmentsDir() . '/' . $receipt->getDocumentPath();
        self::assertFileExists($absolutePath);
        self::assertStringContainsString($receipt->getExternalReference(), (string) file_get_contents($absolutePath));
        self::assertSame($absolutePath, $manager->documentFile($receipt));
    }

    /**
     * Imported by the cron container into a directory of its own, the
     * document was never there for the page that serves it — a 404 (test
     * instance, 06/10/2026). Missing, it is fetched again from the platform.
     */
    public function testAMissingDocumentIsFetchedAgain(): void
    {
        $this->configureActiveTestProvider();
        $manager = self::getContainer()->get(ElectronicInvoiceReceiptManager::class);
        $receipt = $manager->importNew($this->company)[0];

        $receipt->setDocumentPath('var/einvoicing/incoming/elsewhere/' . $receipt->getExternalReference() . '.pdf');

        $path = $manager->documentFile($receipt);

        self::assertNotNull($path);
        self::assertFileExists($path);
        self::assertStringStartsWith(self::attachmentsDir() . '/einvoicing/incoming/', $path);
        self::assertStringStartsWith('einvoicing/incoming/', (string) $receipt->getDocumentPath());
    }

    private static function attachmentsDir(): string
    {
        $directory = self::getContainer()->getParameter('kernel.project_dir') . '/var/cache/test/attachments';
        self::assertIsString($directory);

        return $directory;
    }

    public function testImportingTwiceDoesNotDuplicateTheSameReceipt(): void
    {
        $this->configureActiveTestProvider();
        $manager = self::getContainer()->get(ElectronicInvoiceReceiptManager::class);

        $first = $manager->importNew($this->company);
        $second = $manager->importNew($this->company);

        self::assertCount(1, $first);
        self::assertCount(0, $second);

        $repository = self::getContainer()->get(ElectronicInvoiceReceiptRepository::class);
        self::assertCount(1, $repository->findAll());
        self::assertTrue($repository->existsForExternalReference($this->company->getId(), 'test_provider', $first[0]->getExternalReference()));
        self::assertSame($first[0]->getExternalReference(), $repository->findLatestExternalReference($this->company->getId(), 'test_provider'));
    }

    /**
     * Importing files the invoice on its own: a Bill is created and, since no
     * supplier matched the seller, one is created too. Nothing is left waiting,
     * so the purchase-invoices panel stays at zero — it only lights up for
     * receipts that could not be filed.
     */
    public function testImportingAutomaticallyCreatesTheBillAndItsSupplier(): void
    {
        $this->configureActiveTestProvider();

        $receiptRepository = self::getContainer()->get(ElectronicInvoiceReceiptRepository::class);
        $billRepository = self::getContainer()->get(BillRepository::class);

        self::assertSame(0, $receiptRepository->countAwaitingBill());

        $imported = self::getContainer()->get(ElectronicInvoiceReceiptManager::class)->importNew($this->company);
        self::assertCount(1, $imported);

        $bill = $billRepository->findOneBy(['electronicInvoiceReceipt' => $imported[0]]);

        self::assertInstanceOf(Bill::class, $bill);
        self::assertSame('DEMO-0001', $bill->getBillNumber());
        self::assertSame(BillStatus::Pending, $bill->getStatus());

        $supplier = $bill->getSupplier();
        self::assertSame('Demo Supplier Inc.', $supplier->getName());
        self::assertTrue($supplier->isSupplier());
        self::assertFalse($supplier->isClient());

        self::assertSame(0, $receiptRepository->countAwaitingBill());
    }

    /**
     * The listener is idempotent, so a re-poll that somehow re-imports the same
     * invoice cannot produce a second bill for it.
     */
    public function testImportingTwiceDoesNotCreateASecondBill(): void
    {
        $this->configureActiveTestProvider();

        $manager = self::getContainer()->get(ElectronicInvoiceReceiptManager::class);
        $manager->importNew($this->company);
        $manager->importNew($this->company);

        self::assertCount(1, self::getContainer()->get(BillRepository::class)->findAll());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function configureActiveTestProvider(array $settings = []): void
    {
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
}
