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

namespace Augias\SaasBundle\Payment\Stripe;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillPaymentMethod;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Manager\BillPaymentManager;
use Augias\BillBundle\Manager\SupplierResolver;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The subscriber's side of a subscription paid by card: HERC SI's invoice,
 * written down in the subscriber's own purchases, already paid by card on the
 * day Stripe took the money. Their books then hold the expense without anyone
 * typing it, and the e-invoice, when it arrives, is attached to this purchase
 * rather than becoming a second one (see BillManager::createFromReceipt()).
 *
 * Runs with the subscriber's company selected, and gives back the company
 * that was selected before. A failure here never stops HERC SI's invoice.
 */
final readonly class SubscriberPurchaseRecorder
{
    public function __construct(
        private CompanySelector $companySelector,
        private EntityManagerInterface $entityManager,
        private TaxIdentifierRepository $taxIdentifiers,
        private SupplierResolver $suppliers,
        private ClientRepository $clients,
        private BillRepository $bills,
        private BillPaymentManager $billPayments,
        private LoggerInterface $logger,
    ) {
    }

    public function record(Invoice $invoice, Company $subscriber, DateTimeImmutable $paidAt, string $stripeInvoiceId): void
    {
        $seller = $invoice->getCompany();
        $previous = $this->companySelector->getCompany();

        // Read with no company selected: HERC SI's identifiers are not the
        // subscriber's, and the subscriber's company filters its own queries.
        $this->companySelector->reset();
        $siren = $this->siren($seller);

        $this->companySelector->switchCompany($subscriber->getId());

        try {
            // By its SIREN first, under whatever label the subscriber typed it;
            // otherwise by name, or made — then given the SIREN as such.
            $supplier = $siren !== null ? $this->clients->findOneByTaxIdentifierValue($subscriber->getId(), $siren) : null;
            $supplier ??= $this->suppliers->resolve($subscriber, $seller->getName(), null);
            $supplier->setIsSupplier(true);

            if ($siren !== null && ! $this->hasIdentifier($supplier, TaxIdentifierType::SIREN)) {
                $supplier->setSiren($siren);
            }

            $existing = $this->bills->findOneBy(['supplier' => $supplier, 'billNumber' => $invoice->getInvoiceId()]);

            if ($existing instanceof Bill) {
                return;
            }

            $amount = BigInteger::of((string) $invoice->getTotal());

            $bill = new Bill();
            $bill->setCompany($subscriber)
                ->setSupplier($supplier)
                ->setBillNumber($invoice->getInvoiceId())
                ->setStatus(BillStatus::Pending)
                ->setIssueDate($paidAt)
                ->setDueDate($paidAt)
                ->setTotalAmount($amount)
                ->setCurrencyCode($invoice->getClient()?->getCurrencyCode() ?? 'EUR')
                ->setSupplyType(SupplyType::Services)
                ->setNotes('Abonnement Augias, payé par carte (Stripe).');

            // HERC SI is in VAT franchise: its invoice carries no VAT to deduct.
            if ($invoice->getTax()->isPositive()) {
                $bill->setTaxAmount(BigInteger::of((string) $invoice->getTax()));
            }

            $this->entityManager->persist($bill);
            $this->entityManager->flush();

            $this->billPayments->recordPayment($bill, $amount, $paidAt, BillPaymentMethod::CreditCard, $stripeInvoiceId, null);
        } catch (Throwable $e) {
            $this->logger->error('Could not record the subscription in the subscriber\'s purchases.', ['exception' => $e, 'invoice' => $invoice->getInvoiceId()]);
        } finally {
            $this->companySelector->reset();

            if ($previous !== null) {
                $this->companySelector->switchCompany($previous);
            }
        }
    }

    private function siren(Company $company): ?string
    {
        $siret = null;

        foreach ($this->taxIdentifiers->findCompanyIdentifiers($company->getId()) as $identifier) {
            $value = preg_replace('/\s+/', '', (string) $identifier->getValue()) ?? '';

            if ($identifier->getLabel() === TaxIdentifierType::SIREN && $value !== '') {
                return $value;
            }

            if ($identifier->getLabel() === TaxIdentifierType::SIRET && \strlen($value) >= 9) {
                $siret = substr($value, 0, 9);
            }
        }

        return $siret;
    }

    private function hasIdentifier(Client $client, string $label): bool
    {
        return $client->getTaxIdentifiers()->exists(static fn (int $key, TaxIdentifier $identifier): bool => $identifier->getLabel() === $label);
    }
}
