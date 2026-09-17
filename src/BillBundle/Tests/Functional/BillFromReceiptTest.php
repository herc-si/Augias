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

namespace Augias\BillBundle\Tests\Functional;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Manager\BillManager;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Filing an electronic invoice that arrived from a supplier nobody has met yet.
 *
 * Written as a functional test on purpose. The listener's own tests mock the
 * entity manager, so they cannot see what actually broke here: the row the
 * database refused.
 */
#[CoversClass(BillManager::class)]
#[Group('functional')]
final class BillFromReceiptTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * The import runs from a cron with no company selected — it walks every
     * tenant with the multi-tenancy filter switched off — so nothing fills in
     * the company for it. Creating a supplier creates a credit balance inside
     * `Client::__construct()`, and that row was left without one:
     * `NOT NULL constraint failed: client_credit.company_id`, followed by a
     * closed EntityManager that took the rest of the batch with it.
     */
    public function testAnInvoiceFromAnUnknownSupplierIsFiledWithNoCompanySelected(): void
    {
        $receipt = $this->receipt();

        // What the import cron looks like: no company selected and the
        // multi-tenancy filter off, because it walks every tenant in turn.
        $this->runAsTheImportCron();

        $bill = self::getContainer()->get(BillManager::class)->createFromReceipt($receipt);

        self::assertInstanceOf(Bill::class, $bill);
        self::assertSame('FA-2026-0001', $bill->getBillNumber());

        $supplier = $bill->getSupplier();

        self::assertTrue($supplier->isSupplier());
        self::assertSame('Burger Queen', $supplier->getName());
        self::assertSame(
            $this->companyReference()->getId(),
            $supplier->getCompany()->getId(),
        );
        // The credit balance the client's constructor made travels with it.
        self::assertSame(
            $this->companyReference()->getId(),
            $supplier->getCredit()->getCompany()->getId(),
        );
    }

    /**
     * And the second invoice of the same batch lands too — the point of the
     * failure was not one unfiled invoice but an EntityManager that closed
     * behind it.
     */
    public function testASecondInvoiceInTheSameBatchStillLands(): void
    {
        $first = $this->receipt('FA-2026-0001', '438907');
        $second = $this->receipt('FA-2026-0002', '462306');

        $this->runAsTheImportCron();

        $manager = self::getContainer()->get(BillManager::class);

        $manager->createFromReceipt($first);
        $manager->createFromReceipt($second);

        self::assertCount(2, self::getContainer()->get(BillRepository::class)->findAll());
        // One supplier, not two: the second invoice matched the first's tax
        // identifier rather than creating another Burger Queen.
        self::assertCount(1, $this->entityManager->getRepository(Client::class)->findBy(['isSupplier' => true]));
    }

    private function runAsTheImportCron(): void
    {
        $selector = self::getContainer()->get(CompanySelector::class);
        self::assertInstanceOf(CompanySelector::class, $selector);
        $selector->reset();

        self::assertNull($selector->getCompany(), 'The premise: nothing fills the company in for this code.');

        $filters = $this->entityManager->getFilters();

        if ($filters->isEnabled('company')) {
            $filters->disable('company');
        }
    }

    private function receipt(string $invoiceNumber = 'FA-2026-0001', string $externalReference = '438907'): ElectronicInvoiceReceipt
    {
        $receipt = new ElectronicInvoiceReceipt()
            ->setProvider('super_pdp')
            ->setExternalReference($externalReference)
            ->setInvoiceNumber($invoiceNumber)
            ->setSellerName('Burger Queen')
            ->setSellerIdentifier('000000002')
            ->setIssueDate(new DateTimeImmutable('2026-09-04'))
            ->setTotalAmount(BigInteger::of(5_000))
            ->setCurrencyCode('EUR');

        $receipt->setCompany($this->companyReference());

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return $receipt;
    }

    private function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }
}
