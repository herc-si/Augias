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
use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\CoreBundle\Config\DesignConfigProvider;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\CoreBundle\Twig\Extension\BrandExtension;
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
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * What a company makes its documents carry: its colour, its footer on every
 * PDF — credit notes included — and, self-hosted too, the design it chose.
 * The bank details have their own test: BankDetailsTest.
 */
#[CoversClass(BrandExtension::class)]
#[CoversClass(DesignConfigProvider::class)]
#[CoversClass(BillingTemplateResolver::class)]
#[Group('functional')]
final class DocumentBrandingTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testEveryPdfCarriesTheFooter(): void
    {
        $this->config()->set(DesignConfigProvider::FOOTER_TEXT, "SARL au capital de 1 000 €\nRCS Paris 123 456 789");

        $invoice = $this->twig()->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $this->invoice()]);
        $creditNote = $this->twig()->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $this->creditNote()]);

        foreach ([$invoice, $creditNote] as $html) {
            self::assertStringContainsString('<htmlpagefooter name="footer">', $html);
            self::assertStringContainsString('RCS Paris 123 456 789', $html);
            self::assertStringContainsString('footer: html_footer;', $html);
            // Two lines of the company's own: the page keeps room for them.
            self::assertStringContainsString('margin-bottom: 33mm;', $html);
        }
    }

    /**
     * mPDF reads the footer as HTML on every page — the generator is the
     * judge, not the markup.
     */
    public function testThePdfsAreGeneratedWithTheFooter(): void
    {
        $this->config()->set(DesignConfigProvider::FOOTER_TEXT, 'RCS Paris 123 456 789');
        $this->config()->set(CompanyBankDetails::IBAN, 'FR7630006000011234567890189');
        $this->config()->set(DesignConfigProvider::ACCENT_COLOR, '#1e4976');
        $generator = self::getContainer()->get(Generator::class);

        foreach ([
            $this->twig()->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $this->invoice()]),
            $this->twig()->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $this->creditNote()]),
        ] as $html) {
            self::assertStringStartsWith('%PDF', $generator->generate($html, protect: false));
        }
    }

    public function testWithoutAFooterNothingIsPrinted(): void
    {
        $html = $this->twig()->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $this->invoice()]);

        self::assertStringNotContainsString('IBAN', $html);
        self::assertStringContainsString('margin-bottom: 25mm;', $html);
    }

    public function testTheBrandColourCarriesTheTotals(): void
    {
        $this->config()->set(DesignConfigProvider::ACCENT_COLOR, '#1E4976');

        $html = $this->twig()->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $this->invoice()]);
        $creditNote = $this->twig()->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $this->creditNote()]);

        self::assertStringContainsString('background-color: #1e4976;', $html);
        self::assertStringContainsString('background-color: #1e4976;', $creditNote);
    }

    public function testAColourThatIsNotOneChangesNothing(): void
    {
        $brand = self::getContainer()->get(BrandExtension::class);

        $this->config()->set(DesignConfigProvider::ACCENT_COLOR, 'bleu');
        self::assertNull($brand->brandColor());

        $this->config()->set(DesignConfigProvider::ACCENT_COLOR, '#fc0');
        self::assertSame('#ffcc00', $brand->brandColor());
        // Light yellow: dark text reads on it.
        self::assertSame('#1e293b', $brand->brandTextColor());

        $this->config()->set(DesignConfigProvider::ACCENT_COLOR, '#1e4976');
        self::assertSame('#ffffff', $brand->brandTextColor());
    }

    /**
     * The credit note now looks like the invoices it corrects: the company's
     * details and the client's, and the invoice it credits on the document.
     */
    public function testTheCreditNoteNamesTheCompanyTheClientAndTheInvoice(): void
    {
        $html = $this->twig()->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $this->creditNote()]);

        self::assertStringContainsString('Branding Client', $html);
        self::assertStringContainsString('FACT-BRAND', $html);
        self::assertStringContainsString((string) $this->config()->get('system/company/company_name'), $html);
    }

    /**
     * Self-hosted, the templates are on the disk: the one chosen is used.
     */
    public function testASelfHostedCompanyUsesTheTemplateItChose(): void
    {
        $this->config()->set(BillingTemplateResolver::TEMPLATE_SETTING_KEY, 'classic');

        self::assertSame(
            '@AugiasInvoice/Templates/classic/pdf.html.twig',
            self::getContainer()->get(BillingTemplateResolver::class)->resolve($this->invoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testTheDesignTabOffersTheTemplatesAndTheBranding(): void
    {
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/settings?section=design')
            ->assertSuccessful()
            ->assertSeeElement('input[name="settings[template]"][value="classic"]')
            ->assertSeeElement('input[name="settings[accent_color]"]')
            ->assertSeeElement('textarea[name="settings[footer_text]"]')
            ->visit('/settings/templates/preview/classic')
            ->assertSuccessful();
    }

    private int $invoices = 0;

    private function invoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($this->companyRef());
        $invoice->setClient($this->client());
        $invoice->setStatus(InvoiceStatus::Pending);
        // One number per invoice in a company: the credit note's own invoice
        // is a second one.
        $invoice->setInvoiceId('FACT-BRAND-' . ++$this->invoices);
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-09-01'));
        $invoice->addLine(new Line()->setDescription('Consulting')->setPrice(12000)->setQty(1));
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    private function creditNote(): CreditNote
    {
        $creditNote = new CreditNote();
        $creditNote->setClient($this->client());
        $creditNote->setCreditNoteId('AV-BRAND');
        $creditNote->setReason(CreditReason::Cancellation);
        $creditNote->setCreditNoteDate(new DateTimeImmutable('2026-09-10'));
        $creditNote->setStatus(CreditNoteStatus::Issued);
        $creditNote->setCompany($this->companyRef());
        $creditNote->setCreditedInvoice($this->invoice());
        $creditNote->addLine(new CreditNoteLine()->setDescription('Consulting')->setPrice(12000)->setQty(1));
        $this->em->persist($creditNote);
        $this->em->flush();

        return $creditNote;
    }

    private function client(): Client
    {
        $client = $this->em->getRepository(Client::class)->findOneBy(['name' => 'Branding Client'])
            ?? ClientFactory::createOne(['company' => $this->company, 'name' => 'Branding Client', 'currencyCode' => 'EUR']);

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

    private function twig(): Environment
    {
        return self::getContainer()->get(Environment::class);
    }
}
