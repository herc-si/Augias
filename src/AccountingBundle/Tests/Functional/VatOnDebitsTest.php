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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Listener\Doctrine\VatOnDebitsSnapshotListener;
use Augias\AccountingBundle\Model\DeclarationResult;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\AccountingBundle\Service\VatReturnCalculator;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\BaseInvoice;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Enum\TaxType;
use Augias\TaxBundle\Twig\Extension\TaxBreakdownExtension;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function uniqid;

/**
 * The option for VAT on debits (CGI art. 269, 2-c): the VAT on services falls
 * due when they are invoiced. Frozen on each document when it is issued, so
 * that switching the option never moves what was issued before.
 */
#[CoversClass(VatOnDebitsSnapshotListener::class)]
#[CoversClass(LedgerFeeder::class)]
#[CoversClass(TaxBreakdownExtension::class)]
#[Group('functional')]
final class VatOnDebitsTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private EntityManagerInterface $entityManager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, ReelNormalRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBic->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $this->client = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    public function testUnderTheOptionServicesAreDeclaredWhenInvoiced(): void
    {
        $this->setOption(true);

        $invoice = $this->invoice('2026-03-20');
        self::assertTrue($invoice->isVatOnDebits());

        $this->pay($invoice, '2026-04-10');

        self::assertSame('20000', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('0', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * Opting afterwards moves nothing: an invoice issued under the cash rule
     * is paid under it.
     */
    public function testAnInvoiceIssuedBeforeOptingStaysDeclaredOnPayment(): void
    {
        $this->setOption(false);
        $invoice = $this->invoice('2026-03-20');

        $this->setOption(true);
        $this->pay($invoice, '2026-04-10');

        self::assertFalse($this->reload($invoice)->isVatOnDebits());
        self::assertSame('0', (string) $this->vatReturn('2026-02-01')->totalDue());
        self::assertSame('20000', (string) $this->vatReturn('2026-05-01')->totalDue());
    }

    /**
     * Frozen when it goes out, not before: a draft does not know yet.
     */
    public function testADraftIsNotDecidedYet(): void
    {
        $this->setOption(true);

        self::assertFalse($this->invoice('2026-03-20', InvoiceStatus::Draft)->hasVatOnDebitsDecided());
    }

    /**
     * A company that charges no VAT has nothing to declare on debits.
     */
    public function testACompanyOutsideTheScopeOfVatIsNeverOnDebits(): void
    {
        $this->setOption(true);
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::VAT_EXEMPT, '1');

        self::assertFalse($this->invoice('2026-03-20')->isVatOnDebits());
    }

    public function testTheInvoiceCarriesTheLegalMention(): void
    {
        $this->setOption(true);
        $extension = self::getContainer()->get(TaxBreakdownExtension::class);

        self::assertSame(BaseInvoice::VAT_ON_DEBITS_MENTION, $extension->vatOnDebitsMention($this->invoice('2026-03-20')));

        $this->setOption(false);

        self::assertNull($extension->vatOnDebitsMention($this->invoice('2026-03-21')));
    }

    private function setOption(bool $on): void
    {
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::VAT_ON_DEBITS, $on ? '1' : '0');
    }

    private function invoice(string $date, InvoiceStatus $status = InvoiceStatus::Pending): Invoice
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus($status);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate(new DateTimeImmutable($date));
        $invoice->addLine(
            new Line()->setDescription('Consulting')->setPrice(100_000)->setQty(1)->addTax(
                new LineTax()
                    ->setNameSnapshot('VAT')
                    ->setRateSnapshot('20')
                    ->setTypeSnapshot(TaxType::Exclusive)
                    ->setCategorySnapshot(TaxCategory::Standard),
            ),
        );

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function pay(Invoice $invoice, string $date): void
    {
        $invoice = $this->reload($invoice);

        $payment = new Payment();
        $payment->setTotalAmount(120_000);
        $payment->setCurrencyCode('EUR');
        $invoice->addPayment($payment);
        $payment->setClient($invoice->getClient());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted(new DateTimeImmutable($date));
        $payment->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        $this->entityManager->persist($payment);
        $this->entityManager->flush();
    }

    private function reload(Invoice $invoice): Invoice
    {
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    private function vatReturn(string $date): DeclarationResult
    {
        $period = self::getContainer()->get(AccountingPeriodManager::class)
            ->periodFor($this->entityManager->find(Company::class, $this->company->getId()), PeriodType::Quarter, new DateTimeImmutable($date));
        $this->entityManager->flush();

        return self::getContainer()->get(VatReturnCalculator::class)->calculate($period, 'EUR');
    }
}
