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

namespace Augias\CoreBundle\Tests\Billing;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Billing\AppliesDefaultTerms;
use Augias\CoreBundle\Billing\DefaultTerms;
use Augias\CoreBundle\Billing\TermsDocument;
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Twig\Components\CreateInvoice;
use Augias\QuoteBundle\DTO\QuoteFormDTO;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\QuoteBundle\Twig\Components\CreateQuote;
use Augias\SettingsBundle\SystemConfig;
use Augias\SettingsBundle\Twig\Components\Settings;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * A new invoice or quote opens with the terms set for its client's type — a
 * business or a private individual — and follows a change of client, until
 * someone writes their own.
 */
#[CoversClass(DefaultTerms::class)]
#[CoversClass(TermsDocument::class)]
#[CoversTrait(AppliesDefaultTerms::class)]
final class DefaultTermsTest extends LiveComponentTest
{
    private const string INVOICE_BUSINESS = "Paiement à 30 jours.\nIndemnité forfaitaire pour frais de recouvrement : 40 €.";

    private const string INVOICE_INDIVIDUAL = 'Paiement à 30 jours.';

    private const string QUOTE_BUSINESS = "Devis valable 30 jours.\nIndemnité de 40 €.";

    private const string CREDIT_NOTE = 'Montant à déduire de vos prochaines factures.';

    private const string QUOTE_INDIVIDUAL = "Devis valable 30 jours.\nDélai de rétractation de 14 jours.";

    protected function setUp(): void
    {
        parent::setUp();

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(TermsDocument::Invoice->settingKey(true), self::INVOICE_BUSINESS);
        $config->set(TermsDocument::Invoice->settingKey(false), self::INVOICE_INDIVIDUAL);
        $config->set(TermsDocument::Quote->settingKey(true), self::QUOTE_BUSINESS);
        $config->set(TermsDocument::Quote->settingKey(false), self::QUOTE_INDIVIDUAL);
        $config->set(TermsDocument::CreditNote->settingKey(), self::CREDIT_NOTE);
    }

    public function testEachClientTypeHasItsOwnTerms(): void
    {
        $terms = self::getContainer()->get(DefaultTerms::class);

        self::assertSame(self::INVOICE_BUSINESS, $terms->forClient(TermsDocument::Invoice, $this->business()));
        self::assertSame(self::INVOICE_INDIVIDUAL, $terms->forClient(TermsDocument::Invoice, $this->individual()));
        // No client yet: the stricter text.
        self::assertSame(self::QUOTE_BUSINESS, $terms->forClient(TermsDocument::Quote, null));
        self::assertSame(self::QUOTE_INDIVIDUAL, $terms->forClient(TermsDocument::Quote, $this->individual()));

        // The same text posted back by a textarea, line endings and all.
        self::assertTrue($terms->isDefault(TermsDocument::Invoice, "Paiement à 30 jours.\r\nIndemnité forfaitaire pour frais de recouvrement : 40 €.\r\n"));
        self::assertFalse($terms->isDefault(TermsDocument::Invoice, 'Paiement à réception.'));
        self::assertFalse($terms->isDefault(TermsDocument::Invoice, ''));
    }

    public function testAnEmptySettingMeansNoTerms(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(TermsDocument::Invoice->settingKey(false), '');

        self::assertNull(self::getContainer()->get(DefaultTerms::class)->for(TermsDocument::Invoice, false));
    }

    public function testANewCompanyGetsTheSuggestedTerms(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);

        // The test company was seeded before setUp() changed them: its rows exist.
        foreach ([TermsDocument::Invoice, TermsDocument::Quote] as $document) {
            foreach ([true, false] as $business) {
                self::assertNotNull($config->get($document->settingKey($business)));
            }
        }

        $translator = self::getContainer()->get('translator');
        self::assertStringContainsString('L441-10', $translator->trans(TermsDocument::Invoice->suggestionKey(true), [], null, 'fr'));
        self::assertStringNotContainsString('40 €', $translator->trans(TermsDocument::Invoice->suggestionKey(false), [], null, 'fr'));
        self::assertStringContainsString('L221-18', $translator->trans(TermsDocument::Quote->suggestionKey(false), [], null, 'fr'));
    }

    public function testTheInvoiceAndQuoteTabsHaveBothTexts(): void
    {
        $settings = $this->createLiveComponent(name: Settings::class, client: $this->client)->actingAs($this->getUser());

        foreach (['invoice', 'quote'] as $section) {
            $html = (string) $settings->set('section', $section)->render();

            self::assertStringContainsString('id="default-terms-title"', $html);
            self::assertStringContainsString('name="settings[default_terms][business]"', $html);
            self::assertStringContainsString('name="settings[default_terms][individual]"', $html);
        }

        // A credit note's: one text, whoever the client.
        $creditNotes = (string) $settings->set('section', 'credit_note')->render();
        self::assertStringContainsString('name="settings[default_terms]"', $creditNotes);
        self::assertStringNotContainsString('settings[default_terms][business]', $creditNotes);
    }

    public function testANewInvoiceFollowsItsClientUntilTheTermsAreEdited(): void
    {
        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2026-10-08');

        $component = $this->createLiveComponent(name: CreateInvoice::class, data: ['dto' => $dto], client: $this->client)
            ->actingAs($this->getUser());

        // No client yet: the business text.
        self::assertSame(self::INVOICE_BUSINESS, $this->terms($component->component()->formValues));

        $component->set('invoice.client', (string) $this->individual()->getId())->render();
        self::assertSame(self::INVOICE_INDIVIDUAL, $this->terms($component->component()->formValues));

        $component->set('invoice.client', (string) $this->business()->getId())->render();
        self::assertSame(self::INVOICE_BUSINESS, $this->terms($component->component()->formValues));

        // Written by hand: a change of client leaves them alone.
        $component->set('invoice.terms', 'Paiement à réception.')->render();
        $component->set('invoice.client', (string) $this->individual()->getId())->render();
        self::assertSame('Paiement à réception.', $this->terms($component->component()->formValues));
    }

    public function testAnEmptiedTermsFieldStaysEmpty(): void
    {
        $component = $this->createLiveComponent(name: CreateQuote::class, data: ['dto' => new QuoteFormDTO()], client: $this->client)
            ->actingAs($this->getUser());

        self::assertSame(self::QUOTE_BUSINESS, $this->terms($component->component()->formValues));

        $component->set('quote.terms', '')->render();
        $component->set('quote.client', (string) $this->individual()->getId())->render();
        self::assertSame('', $this->terms($component->component()->formValues));
    }

    public function testANewQuoteForAPrivateIndividualOpensWithTheirTerms(): void
    {
        $dto = new QuoteFormDTO();
        $dto->client = $this->individual();

        $component = $this->createLiveComponent(name: CreateQuote::class, data: ['dto' => $dto], client: $this->client)
            ->actingAs($this->getUser());

        self::assertSame(self::QUOTE_INDIVIDUAL, $this->terms($component->component()->formValues));
    }

    public function testAnInvoiceFromAQuoteTakesTheInvoicesTermsUnlessTheQuoteHadItsOwn(): void
    {
        $manager = self::getContainer()->get(InvoiceManager::class);

        $withDefault = $manager->draftFromQuote($this->quote(self::QUOTE_INDIVIDUAL, $this->individual()));
        self::assertSame(self::INVOICE_INDIVIDUAL, $withDefault->getTerms());

        $withOwn = $manager->draftFromQuote($this->quote('Acompte de 30 % à la commande.', $this->individual()));
        self::assertSame('Acompte de 30 % à la commande.', $withOwn->getTerms());
    }

    /**
     * A credit note gives money back: the invoice's payment term and late
     * payment penalties are not copied onto it, the credit notes' own text is.
     */
    public function testACreditNoteTakesItsOwnTextNotTheInvoices(): void
    {
        $forms = self::getContainer()->get(CreditNoteFormManager::class);

        self::assertSame(self::CREDIT_NOTE, $forms->blank()->terms);

        $invoice = self::getContainer()->get(InvoiceManager::class)->draftFromQuote($this->quote(self::QUOTE_BUSINESS, $this->business()));
        self::assertSame(self::INVOICE_BUSINESS, $invoice->getTerms());
        self::assertSame(self::CREDIT_NOTE, $forms->cancellationOf($invoice)->terms);

        self::assertSame('credit_note/default_terms', TermsDocument::CreditNote->settingKey(false));
        self::assertTrue(self::getContainer()->get(DefaultTerms::class)->isDefault(TermsDocument::CreditNote, self::CREDIT_NOTE));
        self::assertStringContainsString('déduire', self::getContainer()->get('translator')->trans(TermsDocument::CreditNote->suggestionKey(), [], null, 'fr'));
    }

    /**
     * @param array<string, mixed> $formValues
     */
    private function terms(array $formValues): string
    {
        return str_replace("\r\n", "\n", (string) ($formValues['terms'] ?? ''));
    }

    private function quote(string $terms, Client $client): Quote
    {
        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => QuoteStatus::Accepted,
            'archived' => null,
            'terms' => $terms,
            'lines' => [new Line()->setDescription('Travaux')->setPrice(BigInteger::of(10000))->setQty(1)->setTotal(BigInteger::of(10000))],
        ]);
    }

    private ?Client $business = null;

    private ?Client $individual = null;

    private function business(): Client
    {
        return $this->business ??= ClientFactory::createOne(['company' => $this->company, 'name' => 'Acme SAS', 'isCompany' => true, 'currencyCode' => 'EUR']);
    }

    private function individual(): Client
    {
        return $this->individual ??= ClientFactory::createOne(['company' => $this->company, 'name' => 'Jeanne Martin', 'isCompany' => false, 'currencyCode' => 'EUR']);
    }
}
