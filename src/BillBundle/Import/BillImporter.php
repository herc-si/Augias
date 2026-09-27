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

namespace Augias\BillBundle\Import;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\BillBundle\Manager\SupplierResolver;
use Augias\CoreBundle\Entity\Company;
use Augias\MoneyBundle\Currency\CurrencyScale;
use Augias\MoneyBundle\Currency\SupportedCurrencies;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;
use Money\Currency;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Ulid;
use function sprintf;
use function str_starts_with;

/**
 * Turns a supplier's Factur-X file into a bill to check.
 *
 * The bill is a draft — the figures are the supplier's, but whether it is a
 * payable of this company is for the user to confirm, as with any bill typed
 * in. The file itself is kept and attached.
 *
 * @see \Augias\BillBundle\Tests\Import\FacturXImportTest
 */
final readonly class BillImporter
{
    public function __construct(
        private FacturXReader $reader,
        private SupplierResolver $suppliers,
        private EntityManagerInterface $entityManager,
        private SystemConfig $systemConfig,
        private SupportedCurrencies $currencies,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private CurrencyScale $scale = new CurrencyScale(),
    ) {
    }

    /**
     * @throws UnreadableInvoice
     */
    public function import(Company $company, string $content): Bill
    {
        $invoice = $this->reader->read($content);

        if (! $invoice instanceof SupplierInvoice) {
            throw new UnreadableInvoice('not_facturx');
        }

        // A supplier's credit note is not a payable; bills have no credit side yet.
        if ($invoice->isCreditNote()) {
            throw new UnreadableInvoice('credit_note');
        }

        if (! $this->currencies->contains($invoice->currency)) {
            throw new UnreadableInvoice('currency');
        }

        $currency = new Currency($invoice->currency);

        $bill = new Bill();
        $bill->setCompany($company)
            ->setSupplier($this->suppliers->resolve($company, $invoice->sellerName, $invoice->sellerIdentifier))
            ->setStatus(BillStatus::Draft)
            ->setBillNumber($invoice->number)
            ->setIssueDate($invoice->issueDate)
            ->setDueDate($invoice->dueDate)
            ->setCurrencyCode($invoice->currency)
            ->setTotalAmount($this->minor($invoice->total, $currency));

        if (null !== $invoice->tax && ! $this->systemConfig->isVatExempt($company)) {
            $bill->setTaxAmount($this->minor($invoice->tax, $currency));
        }

        $pdf = str_starts_with($content, '%PDF');
        $path = sprintf('var/bills/%s/%s.%s', $company->getId()->toBase58(), new Ulid(), $pdf ? 'pdf' : 'xml');
        $this->filesystem->dumpFile($this->projectDir . '/' . $path, $content);
        $bill->setDocumentPath($path)->setDocumentMimeType($pdf ? 'application/pdf' : 'application/xml');

        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $bill;
    }

    private function minor(BigDecimal $amount, Currency $currency): BigInteger
    {
        return $this->scale->toMinorUnit($amount, $currency)->toScale(0, RoundingMode::HalfEven)->toBigInteger();
    }
}
