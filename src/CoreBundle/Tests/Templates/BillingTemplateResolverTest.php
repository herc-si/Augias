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

namespace Augias\CoreBundle\Tests\Templates;

use Augias\CoreBundle\Contracts\PaidSubscriptionGateInterface;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Templates\BillingDocumentType;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateRegistry;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as M;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\Filesystem\Filesystem;
use function dirname;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(BillingTemplateResolver::class)]
final class BillingTemplateResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $invoiceDir;

    private string $quoteDir;

    private Filesystem $filesystem;

    private Company $company;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $base = sys_get_temp_dir() . '/' . uniqid('billing_resolver_', true);
        $this->invoiceDir = $base . '/invoice';
        $this->quoteDir = $base . '/quote';

        foreach (['pdf', 'email', 'preview'] as $channel) {
            $this->filesystem->dumpFile(sprintf('%s/sleek/%s.html.twig', $this->invoiceDir, $channel), 'sleek');
        }

        $this->filesystem->mkdir($this->quoteDir);

        $this->company = new Company();
        $this->company->setName('Test Co');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove(dirname($this->invoiceDir));
    }

    /**
     * @param array{saasEnabled?: bool, slug?: string|null, subscriptionActive?: bool, featureEnabled?: bool} $config
     */
    private function createResolver(array $config = []): BillingTemplateResolver
    {
        $registry = new BillingTemplateRegistry([
            BillingDocumentType::Invoice->value => $this->invoiceDir,
            BillingDocumentType::Quote->value => $this->quoteDir,
        ]);

        $toggle = M::mock(ToggleInterface::class);
        $toggle->allows('isActive')->with('saas_enabled')->andReturn($config['saasEnabled'] ?? true);

        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->allows('get')
            ->with(BillingTemplateResolver::TEMPLATE_SETTING_KEY, $this->company)
            ->andReturn(array_key_exists('slug', $config) ? $config['slug'] : 'sleek');

        $subscriptionGate = M::mock(PaidSubscriptionGateInterface::class);
        $subscriptionGate->allows('isActive')->with($this->company)->andReturn($config['subscriptionActive'] ?? true);

        $featureGate = M::mock(FeatureGate::class);
        $featureGate->allows('isEnabled')
            ->with(Feature::CustomTemplates->value, $this->company)
            ->andReturn($config['featureEnabled'] ?? true);

        return new BillingTemplateResolver($registry, $systemConfig, $featureGate, $subscriptionGate, $toggle);
    }

    private function createInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($this->company);

        return $invoice;
    }

    private function createQuote(): Quote
    {
        $quote = new Quote();
        $quote->setCompany($this->company);

        return $quote;
    }

    public function testResolvesTheSelectedTemplateWhenAllGatesPass(): void
    {
        $resolver = $this->createResolver();

        self::assertSame(
            '@AugiasInvoice/Templates/sleek/pdf.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
        self::assertSame(
            '@AugiasInvoice/Templates/sleek/email.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Email),
        );
        self::assertSame(
            '@AugiasInvoice/Templates/sleek/preview.html.twig',
            $resolver->customTemplate($this->createInvoice(), BillingTemplateChannel::View),
        );
    }

    /**
     * Self-hosted, the templates are on the disk and the choice is the
     * owner's: neither a plan nor a subscription is asked.
     */
    public function testSelfHostedUsesTheChosenTemplateWithoutAnyGate(): void
    {
        $resolver = $this->createResolver(['saasEnabled' => false, 'subscriptionActive' => false, 'featureEnabled' => false]);

        self::assertSame(
            '@AugiasInvoice/Templates/sleek/pdf.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testSelfHostedStillFallsBackWhenNothingIsChosen(): void
    {
        $resolver = $this->createResolver(['saasEnabled' => false, 'slug' => null]);

        self::assertSame(
            '@AugiasInvoice/Pdf/invoice.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testFallsBackToDefaultWhenNoTemplateIsSelected(): void
    {
        self::assertNull(
            $this->createResolver(['slug' => null])->customTemplate($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
        self::assertNull(
            $this->createResolver(['slug' => ''])->customTemplate($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
        self::assertNull(
            $this->createResolver(['slug' => BillingTemplateRegistry::DEFAULT_SLUG])->customTemplate($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testFallsBackToDefaultWhenSubscriptionIsNotActive(): void
    {
        $resolver = $this->createResolver(['subscriptionActive' => false]);

        self::assertSame(
            '@AugiasInvoice/Pdf/invoice.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testFallsBackToDefaultWhenFeatureIsNotOnThePlan(): void
    {
        $resolver = $this->createResolver(['featureEnabled' => false]);

        self::assertSame(
            '@AugiasInvoice/Email/invoice.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Email),
        );
    }

    public function testFallsBackToDefaultWhenTheSelectedTemplateDoesNotExist(): void
    {
        $resolver = $this->createResolver(['slug' => 'removed-template']);

        self::assertSame(
            '@AugiasInvoice/Pdf/invoice.html.twig',
            $resolver->resolve($this->createInvoice(), BillingTemplateChannel::Pdf),
        );
    }

    public function testQuoteFallsBackToDefaultWhenTheQuoteVariantIsMissing(): void
    {
        // "sleek" only ships an invoice design in this fixture.
        $resolver = $this->createResolver();

        self::assertSame(
            '@AugiasQuote/Pdf/quote.html.twig',
            $resolver->resolve($this->createQuote(), BillingTemplateChannel::Pdf),
        );
        self::assertSame(
            '@AugiasQuote/Email/quote.html.twig',
            $resolver->resolve($this->createQuote(), BillingTemplateChannel::Email),
        );
        self::assertNull($resolver->customTemplate($this->createQuote(), BillingTemplateChannel::View));
    }

    public function testQuoteResolvesTheSelectedTemplateWhenTheVariantExists(): void
    {
        foreach (['pdf', 'email', 'preview'] as $channel) {
            $this->filesystem->dumpFile(sprintf('%s/sleek/%s.html.twig', $this->quoteDir, $channel), 'sleek');
        }

        self::assertSame(
            '@AugiasQuote/Templates/sleek/pdf.html.twig',
            $this->createResolver()->resolve($this->createQuote(), BillingTemplateChannel::Pdf),
        );
    }

    public function testDefaultTemplates(): void
    {
        self::assertSame(
            '@AugiasInvoice/external_invoice_view.html.twig',
            BillingTemplateResolver::defaultTemplate(BillingDocumentType::Invoice, BillingTemplateChannel::View),
        );
        self::assertSame(
            '@AugiasQuote/quote_template.html.twig',
            BillingTemplateResolver::defaultTemplate(BillingDocumentType::Quote, BillingTemplateChannel::View),
        );
    }
}
