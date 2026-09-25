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

namespace Augias\BillBundle\Manager;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts an imported {@see ElectronicInvoiceReceipt} into a tracked
 * {@see Bill} — a deliberate, explicit step (triggered from the "Create Bill"
 * action on the receipt) rather than something the receipt import itself
 * does automatically: a received document may be a duplicate or need
 * correction before it becomes a tracked liability.
 *
 * @see \Augias\BillBundle\Tests\Manager\BillManagerTest
 */
final readonly class BillManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClientRepository $clientRepository,
        private SystemConfig $systemConfig,
    ) {
    }

    public function createFromReceipt(ElectronicInvoiceReceipt $receipt): Bill
    {
        $bill = new Bill();
        $bill->setCompany($receipt->getCompany())
            ->setSupplier($this->resolveSupplier($receipt))
            ->setBillNumber($receipt->getInvoiceNumber())
            ->setStatus(BillStatus::Pending)
            ->setIssueDate($receipt->getIssueDate())
            ->setTotalAmount($receipt->getTotalAmount() ?? BigInteger::zero())
            ->setCurrencyCode($receipt->getCurrencyCode() ?? 'EUR')
            ->setElectronicInvoiceReceipt($receipt)
            // When its VAT is deductible, read off the invoice rather than
            // typed again: goods or services, and whether the supplier opted
            // for debits.
            ->setSupplyType($receipt->getSupplyType() ?? SupplyType::Services)
            ->setSupplierVatOnDebits($receipt->isSupplierVatOnDebits());

        // Only for a company that deducts VAT at all — one in franchise en
        // base has no use for it, and is not asked for it on the form either.
        if (! $this->systemConfig->isVatExempt($receipt->getCompany())) {
            $bill->setTaxAmount($receipt->getTaxAmount());
        }

        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $bill;
    }

    /**
     * Matches an existing supplier — a {@see Client} flagged
     * {@see Client::isSupplier()} — by its tax identifier or name (both as
     * reported by the provider) before creating a new one, so importing the
     * same supplier's invoices repeatedly doesn't pile up duplicate records.
     */
    private function resolveSupplier(ElectronicInvoiceReceipt $receipt): Client
    {
        $sellerIdentifier = $receipt->getSellerIdentifier();

        if ($sellerIdentifier !== null) {
            $existing = $this->clientRepository->findOneByTaxIdentifierValue($receipt->getCompany()->getId(), $sellerIdentifier);

            if ($existing instanceof Client) {
                $existing->setIsSupplier(true);

                return $existing;
            }
        }

        $sellerName = $receipt->getSellerName();

        if ($sellerName !== null) {
            $existing = $this->clientRepository->findOneByName($receipt->getCompany()->getId(), $sellerName);

            if ($existing instanceof Client) {
                $existing->setIsSupplier(true);

                return $existing;
            }
        }

        $client = new Client();
        $client->setCompany($receipt->getCompany())
            ->setName($sellerName ?? 'Unknown supplier')
            ->setIsClient(false)
            ->setIsSupplier(true);

        if ($sellerIdentifier !== null) {
            $identifier = new TaxIdentifier();
            $identifier->setCompany($receipt->getCompany())
                ->setLabel('Tax ID')
                ->setValue($sellerIdentifier);

            $client->addTaxIdentifier($identifier);
        }

        $this->entityManager->persist($client);

        return $client;
    }
}
