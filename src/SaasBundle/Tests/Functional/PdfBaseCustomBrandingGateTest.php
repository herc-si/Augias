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

namespace Augias\SaasBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Config\DesignConfigProvider;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Twig\Extension\BrandExtension;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\SettingsBundle\Entity\Setting;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Twig\Environment;

/**
 * Verifies who decides whether the PDF footer says "Powered By":
 *
 * - On the hosted service, `system/general/hide_powered_by` counts only with
 *   the `custom_branding` feature: without it (Free/Solo plans) the line is
 *   always rendered, whatever the stored value.
 * - Self-hosted, the owner's box on the design tab (`design/hide_powered_by`)
 *   decides; the hosted switch counts for nothing.
 *
 * The "Powered By" content lives in the `<pagefooter content-left=...>`
 * attribute of the rendered template (see `classic/pdf.html.twig` which
 * extends `_pdf_base.html.twig`).
 */
#[Group('functional')]
final class PdfBaseCustomBrandingGateTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private const string SETTING_KEY = 'system/general/hide_powered_by';

    public function testGatedPlanAlwaysShowsPoweredByEvenWhenSettingHides(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        // SaaS plan that does NOT include custom_branding: the gate is OFF.
        $featureGate = $this->createStub(FeatureGate::class);
        $featureGate->method('isEnabled')
            ->willReturnCallback(static fn (string $key): bool => $key !== 'custom_branding');
        self::getContainer()->set(FeatureGate::class, $featureGate);
        $this->hosted($featureGate);

        $this->reloadCompany();
        // Persist hide_powered_by=1 in the database (the "I'm hiding it" intent
        // baked in from a prior plan that had custom_branding).
        $this->seedHidePoweredBy('1');

        $output = $this->renderPdfTemplate();

        // The "Powered By" attribute is populated despite the setting being '1'.
        self::assertStringContainsString('Powered By', $output);
    }

    public function testUngatedPlanRespectsHidePoweredBySetting(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $featureGate = $this->createStub(FeatureGate::class);
        $featureGate->method('isEnabled')
            ->willReturn(true);
        self::getContainer()->set(FeatureGate::class, $featureGate);
        $this->hosted($featureGate);

        $this->reloadCompany();
        $this->seedHidePoweredBy('1');

        $output = $this->renderPdfTemplate();

        // With custom_branding ON and hide_powered_by=1, the attribute is empty.
        self::assertStringNotContainsString('Powered By', $output);
    }

    public function testUngatedPlanShowsPoweredByWhenSettingNotHidden(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $featureGate = $this->createStub(FeatureGate::class);
        $featureGate->method('isEnabled')
            ->willReturn(true);
        self::getContainer()->set(FeatureGate::class, $featureGate);
        $this->hosted($featureGate);

        $this->reloadCompany();
        $this->seedHidePoweredBy('0');

        $output = $this->renderPdfTemplate();

        self::assertStringContainsString('Powered By', $output);
    }

    public function testSelfHostedTheDesignTabBoxDecides(): void
    {
        if (($_ENV['AUGIAS_PLATFORM'] ?? $_SERVER['AUGIAS_PLATFORM'] ?? null) === 'saas') {
            self::markTestSkipped('Self-hosted scenario is exercised in non-SaaS test runs only.');
        }

        $invoice = $this->createFixtureInvoice();

        // The hosted switch alone hides nothing self-hosted...
        $this->seedHidePoweredBy('1');
        self::assertStringContainsString('Powered By', $this->renderPdfTemplate($invoice));

        // ...the owner's box does.
        $this->seedHidePoweredBy('1', DesignConfigProvider::HIDE_POWERED_BY);
        self::assertStringNotContainsString('Powered By', $this->renderPdfTemplate($invoice));
    }

    /**
     * The hosted service, for the one service that asks: the toggle itself is
     * shared by too many to be swapped.
     */
    private function hosted(FeatureGate $featureGate): void
    {
        $toggle = $this->createStub(ToggleInterface::class);
        $toggle->method('isActive')->willReturnCallback(static fn (string $feature): bool => 'saas_enabled' === $feature);

        self::getContainer()->set(BrandExtension::class, new BrandExtension(self::getContainer()->get(SystemConfig::class), $toggle, $featureGate));
    }

    private function reloadCompany(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $company = $em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->company = $company;

        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());
    }

    private function seedHidePoweredBy(string $value, string $key = self::SETTING_KEY): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        // Try to update via the repository first; if the row doesn't exist yet
        // (self-hosted has no SaasBundle ConfigProvider seeding it), persist a
        // new row directly so the template can read a deterministic value.
        $repo = $em->getRepository(Setting::class);
        $existing = $repo->findOneBy(['key' => $key]);

        if ($existing instanceof Setting) {
            $existing->setValue($value);
            $em->flush();

            return;
        }

        $setting = new Setting();
        $setting->setKey($key);
        $setting->setValue($value);
        $setting->setType(CheckboxType::class);
        $setting->setDefaultValue('0');
        $setting->setCompany($this->company);

        $em->persist($setting);
        $em->flush();
    }

    private function renderPdfTemplate(?Invoice $invoice = null): string
    {
        $invoice ??= $this->createFixtureInvoice();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(
            '@AugiasInvoice/Templates/classic/pdf.html.twig',
            ['invoice' => $invoice]
        );
    }

    private function createFixtureInvoice(): Invoice
    {
        $client = ClientFactory::createOne([
            'company' => $this->company,
            'name' => 'Acme Corp',
            'currencyCode' => 'USD',
        ]);

        $contact = ContactFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        return InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => InvoiceStatus::Pending,
            'invoiceId' => 'INV-FIXTURE-001',
            'due' => CarbonImmutable::now()->addDays(14),
            'paidDate' => null,
            'archived' => null,
            'terms' => 'Payment due within 30 days.',
            'notes' => 'Thank you for your business.',
            'balance' => BigInteger::of(150000),
            'total' => BigInteger::of(150000),
            'baseTotal' => BigInteger::of(150000),
            'tax' => BigInteger::of(0),
            'discount' => new Discount()
                ->setType(null),
            'lines' => [
                new Line()
                    ->setDescription('Sample line item')
                    ->setPrice(BigInteger::of(75000))
                    ->setQty(2)
                    ->setTotal(BigInteger::of(150000)),
            ],
            'users' => [$contact],
        ]);
    }
}
