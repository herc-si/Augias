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

namespace Augias\AccountingBundle\Tests\Bank;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Bank\Reconciler;
use Augias\AccountingBundle\Bank\ReconciliationRefused;
use Augias\AccountingBundle\Bank\ReconciliationSuggester;
use Augias\AccountingBundle\Bank\StatementImporter;
use Augias\AccountingBundle\Bank\Suggestion;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Entity\BankTransaction;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\BankTransactionStatus;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Tests\Dashboard\AccountingWidgetTestCase;
use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use function array_filter;
use function explode;
use function file_get_contents;

/**
 * From a statement file to the books: import, what each line most likely
 * is, and the payment a match records.
 */
#[CoversClass(StatementImporter::class)]
#[CoversClass(ReconciliationSuggester::class)]
#[CoversClass(Reconciler::class)]
final class ReconciliationTest extends AccountingWidgetTestCase
{
    public function testImportingTheSameStatementTwiceAddsNothingTwice(): void
    {
        $account = $this->account();
        $csv = "Date;Libellé;Montant\n24/09/2026;Café;-3,50\n24/09/2026;Café;-3,50\n25/09/2026;VIR ACME;120,00\n";

        $first = $this->importer()->import($account, $csv);
        $again = $this->importer()->import($account, $csv);

        // Two coffees the same day are two lines, not one seen twice.
        self::assertSame(3, $first->imported);
        self::assertSame(0, $again->imported);
        self::assertSame(3, $again->duplicates);
        self::assertSame('CSV', $first->format);

        $line = $this->entityManager->getRepository(BankTransaction::class)->findOneBy(['label' => 'VIR ACME']);
        self::assertInstanceOf(BankTransaction::class, $line);
        self::assertTrue($line->getAmount()->isEqualTo(12000), 'Stored in cents.');
    }

    public function testAStatementInAnotherCurrencyIsRefused(): void
    {
        $this->expectException(UnreadableStatement::class);

        // The fixture is in euros; the account in dollars.
        $this->importer()->import($this->account(currency: 'USD'), (string) file_get_contents(__DIR__ . '/Fixtures/camt053.xml'));
    }

    /**
     * Money in matching an invoice still owed: matching records the payment,
     * the invoice is paid, and the books take the receipt on the bank's date.
     */
    public function testMatchingAnOwedInvoiceRecordsItsPayment(): void
    {
        $this->configureMicroEntreprise();
        $invoice = $this->owedInvoice(12000, 'FACT-2026-0042', isCompany: true);
        $line = $this->importLine('VIR ACME FACT-2026-0042;120,00');

        $suggestions = $this->suggester()->suggest($line);
        self::assertNotEmpty($suggestions);
        self::assertSame(Suggestion::INVOICE, $suggestions[0]->kind);
        self::assertSame((string) $invoice->getId(), $suggestions[0]->id);

        $this->reconciler()->match($line, Suggestion::INVOICE, $suggestions[0]->id);

        $this->entityManager->clear();
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        $line = $this->entityManager->find(BankTransaction::class, $line->getId());
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertInstanceOf(BankTransaction::class, $line);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame(BankTransactionStatus::Matched, $line->getStatus());
        self::assertInstanceOf(Payment::class, $line->getPayment());
        self::assertSame('2026-09-24', $line->getPayment()->getCompleted()?->format('Y-m-d'));

        $entry = $this->entityManager->getRepository(LedgerEntry::class)->findOneBy(['book' => LedgerBook::Revenue, 'sourceId' => $line->getPayment()->getId()]);
        self::assertInstanceOf(LedgerEntry::class, $entry, 'The receipt reaches the books through the payment.');
        self::assertSame('2026-09-24', $entry->getEntryDate()->format('Y-m-d'));

        // And the line proves nothing twice.
        self::assertSame([], $this->suggester()->suggest($this->importLine('VIR ACME FACT-2026-0042;120,00', '25/09/2026')));
    }

    public function testAPaymentAlreadyRecordedIsOnlyConfirmed(): void
    {
        $this->configureMicroEntreprise();
        $invoice = $this->owedInvoice(12000, 'FACT-2026-0043', isCompany: true);
        $this->reconciler()->match($this->importLine('VIR ACME;120,00', '20/09/2026'), Suggestion::INVOICE, (string) $invoice->getId());
        $payments = $this->entityManager->getRepository(Payment::class)->count([]);

        // The same money seen on a second account's statement, days later.
        $other = $this->account('Autre banque');
        $line = $this->importLine('ACME;120,00', '23/09/2026', $other);
        $line->reopen();
        $this->entityManager->flush();

        $suggestions = $this->suggester()->suggest($line);

        self::assertSame([], array_filter($suggestions, static fn (Suggestion $s): bool => Suggestion::PAYMENT === $s->kind), 'Already matched to the first line.');
        self::assertSame($payments, $this->entityManager->getRepository(Payment::class)->count([]));
    }

    public function testMoneyOutPaysASupplierBill(): void
    {
        $this->configureReelNormal();
        $bill = $this->owedBill(4990, 'HEB-7');
        $line = $this->importLine('PRLV HEBERGEUR HEB-7;-49,90');

        $suggestions = $this->suggester()->suggest($line);
        self::assertSame(Suggestion::BILL, $suggestions[0]->kind);

        $this->reconciler()->match($line, Suggestion::BILL, (string) $bill->getId());

        $this->entityManager->clear();
        $bill = $this->entityManager->find(Bill::class, $bill->getId());
        self::assertInstanceOf(Bill::class, $bill);
        self::assertSame(BillStatus::Paid, $bill->getStatus());
    }

    /**
     * A private customer's payment is kept only where the books are — the
     * rule of the cash register holds here as on the payment screen.
     */
    public function testAPrivateCustomersPaymentStillNeedsTheBooks(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '0');
        $invoice = $this->owedInvoice(12000, 'FACT-2026-0044', isCompany: false);
        $line = $this->importLine('VIR DUPONT;120,00');

        $this->expectException(ReconciliationRefused::class);
        $this->expectExceptionMessage('books_required');

        $this->reconciler()->match($line, Suggestion::INVOICE, (string) $invoice->getId());
    }

    public function testALineSetAsideCanBeTakenBack(): void
    {
        $line = $this->importLine('VIREMENT INTERNE;500,00');

        $this->reconciler()->ignore($line);
        self::assertSame(BankTransactionStatus::Ignored, $line->getStatus());

        $this->reconciler()->reopen($line);
        self::assertSame(BankTransactionStatus::Unmatched, $line->getStatus());
    }

    private function account(string $name = 'Compte courant', string $currency = 'EUR'): BankAccount
    {
        $account = new BankAccount()->setName($name)->setCurrencyCode($currency);

        $account->setCompany($this->companyReference());
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function importLine(string $row, string $date = '24/09/2026', ?BankAccount $account = null): BankTransaction
    {
        $account ??= $this->account();
        [$label] = explode(';', $row);
        $this->importer()->import($account, "Date;Libellé;Montant\n" . $date . ';' . $row . "\n");

        $line = $this->entityManager->getRepository(BankTransaction::class)->findOneBy(['bankAccount' => $account, 'label' => $label], ['created' => 'DESC']);
        self::assertInstanceOf(BankTransaction::class, $line);

        return $line;
    }

    private function owedInvoice(int $cents, string $number, bool $isCompany): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'isCompany' => $isCompany, 'name' => ($isCompany ? 'Acme ' : 'Jean Dupont ') . $number]);
        $this->bankTransferMethod();

        $invoice = new Invoice();
        $invoice->setCompany($this->companyReference());
        $invoice->setClient($this->entityManager->find(Client::class, $client->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId($number);
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-09-01'));
        // The total, and so the balance, are worked out from the lines.
        $invoice->addLine(new Line()->setDescription('Consulting')->setPrice($cents)->setQty(1));

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function bankTransferMethod(): void
    {
        if (null !== $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['gatewayName' => 'bank_transfer'])) {
            return;
        }

        $method = new PaymentMethod();
        $method->setName('Virement');
        $method->setGatewayName('bank_transfer');
        $method->setFactoryName(PaymentMethod::FACTORY_OFFLINE);
        $method->setInternal(true);
        $method->setEnabled(true);
        $method->setCompany($this->companyReference());
        $this->entityManager->persist($method);
        $this->entityManager->flush();
    }

    private function owedBill(int $cents, string $number): Bill
    {
        $supplier = new Client();
        $supplier->setCompany($this->companyReference())->setName('Hébergeur ' . $number)->setIsClient(false)->setIsSupplier(true)->setCurrencyCode('EUR');
        $this->entityManager->persist($supplier);

        $bill = new Bill();
        $bill->setCompany($this->companyReference())
            ->setSupplier($supplier)
            ->setBillNumber($number)
            ->setIssueDate(new DateTimeImmutable('2026-09-01'))
            ->setDueDate(new DateTimeImmutable('2026-09-30'))
            ->setTotalAmount(BigInteger::of($cents))
            ->setCurrencyCode('EUR')
            ->setStatus(BillStatus::Pending);
        $this->entityManager->persist($bill);
        $this->entityManager->flush();

        return $bill;
    }

    private function importer(): StatementImporter
    {
        return self::getContainer()->get(StatementImporter::class);
    }

    private function suggester(): ReconciliationSuggester
    {
        return self::getContainer()->get(ReconciliationSuggester::class);
    }

    private function reconciler(): Reconciler
    {
        return self::getContainer()->get(Reconciler::class);
    }
}
