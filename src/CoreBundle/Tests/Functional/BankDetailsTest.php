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

namespace Augias\CoreBundle\Tests\Functional;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\BankDetails;
use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Form\Type\BicType;
use Augias\CoreBundle\Form\Type\IbanType;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * The bank details a company enters under Settings › Company, and where they
 * go: on the invoices its clients still have to pay, with the number to quote,
 * in every design — and nowhere else.
 */
#[CoversClass(CompanyBankDetails::class)]
#[CoversClass(BankDetails::class)]
#[CoversClass(IbanType::class)]
#[CoversClass(BicType::class)]
#[Group('functional')]
final class BankDetailsTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private int $invoices = 0;

    public function testTheCompanyTabHasTheBankDetailsAndTheDesignTabNoLonger(): void
    {
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/settings?section=system')
            ->assertSuccessful()
            ->assertSeeElement('#bank-details-title')
            ->assertSeeElement('input[name="settings[company][bank_details][bank_name]"]')
            ->assertSeeElement('input[name="settings[company][bank_details][iban]"]')
            ->assertSeeElement('input[name="settings[company][bank_details][bic]"]')
            ->visit('/settings?section=design')
            ->assertSuccessful()
            ->assertNotSeeElement('input[name="settings[iban]"]');
    }

    public function testAnIbanIsCheckedAndKeptAsOnARib(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);

        $iban = $forms->create(IbanType::class);
        $iban->submit(' fr7630006000011234567890189 ');
        self::assertTrue($iban->isValid());
        self::assertSame('FR76 3000 6000 0112 3456 7890 189', $iban->getData());

        // A check digit off.
        $wrong = $forms->create(IbanType::class);
        $wrong->submit('FR7630006000011234567890188');
        self::assertFalse($wrong->isValid());

        $bic = $forms->create(BicType::class);
        $bic->submit(' agrifrpp ');
        self::assertTrue($bic->isValid());
        self::assertSame('AGRIFRPP', $bic->getData());

        $wrongBic = $forms->create(BicType::class);
        $wrongBic->submit('AGRI');
        self::assertFalse($wrongBic->isValid());

        // Left empty: nothing to check, nothing printed.
        $empty = $forms->create(IbanType::class);
        $empty->submit('');
        self::assertTrue($empty->isValid());
    }

    public function testNothingWithoutAnIban(): void
    {
        $this->config()->set(CompanyBankDetails::BANK_NAME, 'Crédit Agricole');
        $this->config()->set(CompanyBankDetails::BIC, 'AGRIFRPP');

        self::assertNull(self::getContainer()->get(CompanyBankDetails::class)->get());
        self::assertStringNotContainsString('payment-details', $this->render('@AugiasInvoice/Pdf/invoice.html.twig', $this->invoice(InvoiceStatus::Pending)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        yield 'default' => ['@AugiasInvoice/Pdf/invoice.html.twig'];

        foreach (['classic', 'compact', 'editorial', 'friendly', 'modern', 'monochrome', 'photographer', 'studio'] as $slug) {
            yield $slug => ['@AugiasInvoice/Templates/' . $slug . '/pdf.html.twig'];
        }
    }

    #[DataProvider('templates')]
    public function testAnInvoiceToPayTellsHowToPayInEveryDesign(string $template): void
    {
        $this->withBankDetails();

        $html = $this->render($template, $this->invoice(InvoiceStatus::Pending));

        self::assertStringContainsString('Règlement par virement', $html);
        self::assertStringContainsString('Crédit Agricole', $html);
        self::assertStringContainsString('FR76 3000 6000 0112 3456 7890 189', $html);
        self::assertStringContainsString('AGRIFRPP', $html);
        self::assertStringContainsString('Référence à indiquer', $html);
        self::assertStringContainsString('FACT-BANKD-', $html);
        // Not in the footer any more: once, next to the totals.
        self::assertSame(1, substr_count($html, 'FR76 3000 6000 0112 3456 7890 189'));
    }

    public function testNotOnADraftNorOnAPaidInvoice(): void
    {
        $this->withBankDetails();

        $draft = $this->invoice(InvoiceStatus::Draft, numbered: false);
        $paid = $this->invoice(InvoiceStatus::Paid);
        $overdue = $this->invoice(InvoiceStatus::Overdue);

        self::assertStringNotContainsString('payment-details', $this->render('@AugiasInvoice/Pdf/invoice.html.twig', $draft));
        self::assertStringNotContainsString('payment-details', $this->render('@AugiasInvoice/Pdf/invoice.html.twig', $paid));
        self::assertStringContainsString('payment-details', $this->render('@AugiasInvoice/Pdf/invoice.html.twig', $overdue));
    }

    public function testNotOnACreditNote(): void
    {
        $this->withBankDetails();

        $creditNote = new CreditNote();
        $creditNote->setClient($this->client());
        $creditNote->setCreditNoteId('AV-BANKD');
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-09-10'));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->companyRef());
        $creditNote->setCreditedInvoice($this->invoice(InvoiceStatus::Pending));
        $creditNote->addLine(new CreditNoteLine()->setDescription('Consulting')->setPrice(12000)->setQty(1));
        $this->em->persist($creditNote);
        $this->em->flush();

        $html = self::getContainer()->get(Environment::class)->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote]);

        self::assertStringNotContainsString('IBAN', $html);
    }

    private function withBankDetails(): void
    {
        $this->config()->set(CompanyBankDetails::BANK_NAME, 'Crédit Agricole');
        $this->config()->set(CompanyBankDetails::IBAN, 'FR76 3000 6000 0112 3456 7890 189');
        $this->config()->set(CompanyBankDetails::BIC, 'AGRIFRPP');
    }

    private function render(string $template, Invoice $invoice): string
    {
        $_SERVER['AUGIAS_LOCALE'] = $_ENV['AUGIAS_LOCALE'] = 'fr_FR';
        self::getContainer()->get('translator')->setLocale('fr');

        return self::getContainer()->get(Environment::class)->render($template, ['invoice' => $invoice]);
    }

    private function invoice(InvoiceStatus $status, bool $numbered = true): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($this->companyRef());
        $invoice->setClient($this->client());
        $invoice->setStatus($status);

        if ($numbered) {
            $invoice->setInvoiceId('FACT-BANKD-' . ++$this->invoices);
        }

        $invoice->setInvoiceDate(new DateTimeImmutable('2026-09-01'));
        $invoice->addLine(new Line()->setDescription('Consulting')->setPrice(12000)->setQty(1));
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    private function client(): Client
    {
        $client = $this->em->getRepository(Client::class)->findOneBy(['name' => 'Bank Details Client'])
            ?? ClientFactory::createOne(['company' => $this->company, 'name' => 'Bank Details Client', 'currencyCode' => 'EUR']);

        $managed = $this->em->find(Client::class, $client->getId());
        self::assertInstanceOf(Client::class, $managed);

        return $managed;
    }

    private function companyRef(): Company
    {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function config(): SystemConfig
    {
        return self::getContainer()->get(SystemConfig::class);
    }
}
