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
use Augias\CoreBundle\Enum\SupplyType;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\SettingsBundle\SystemConfig;
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
        private SupplierResolver $suppliers,
        private SystemConfig $systemConfig,
    ) {
    }

    public function createFromReceipt(ElectronicInvoiceReceipt $receipt): Bill
    {
        $bill = new Bill();
        $bill->setCompany($receipt->getCompany())
            ->setSupplier($this->suppliers->resolve($receipt->getCompany(), $receipt->getSellerName(), $receipt->getSellerIdentifier()))
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
}
