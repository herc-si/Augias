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

namespace Augias\InvoiceBundle\Tests\Functional\Templates;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Pdf\Generator;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\InvoiceBundle\Twig\Extension\InvoiceTemplateExtension;
use Augias\SettingsBundle\Entity\Setting;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

#[CoversClass(InvoiceTemplateExtension::class)]
final class TemplatesRenderingTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private const array CHANNELS = ['pdf', 'email', 'preview'];

    /**
     * Slugs are discovered from the filesystem so a newly added template
     * directory is covered automatically.
     *
     * @return list<string>
     */
    private static function slugs(): array
    {
        $slugs = [];

        foreach (glob(dirname(__DIR__, 3) . '/Resources/views/Templates/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $slug = basename($directory);

            if (! str_starts_with($slug, '_')) {
                $slugs[] = $slug;
            }
        }

        sort($slugs);

        self::assertNotEmpty($slugs, 'No invoice design templates found');

        return $slugs;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templateProvider(): iterable
    {
        foreach (self::slugs() as $slug) {
            foreach (self::CHANNELS as $channel) {
                yield sprintf('%s/%s', $slug, $channel) => [$slug, $channel];
            }
        }
    }

    #[DataProvider('templateProvider')]
    public function testTemplateRenders(string $slug, string $channel): void
    {
        $invoice = $this->createFixtureInvoice();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $output = $twig->render(
            sprintf('@AugiasInvoice/Templates/%s/%s.html.twig', $slug, $channel),
            ['invoice' => $invoice]
        );

        self::assertNotEmpty($output, sprintf('Template %s/%s produced empty output', $slug, $channel));
        self::assertStringContainsString($invoice->getInvoiceId(), $output);
        self::assertStringContainsString((string) $invoice->getClient(), $output);

        match ($channel) {
            // PDF and preview render every line item and the company logo —
            // assert both surface so a regression that drops
            // `{% for line in invoice.lines %}` or the logo block is caught.
            'pdf' => $this->assertChannelContains($output, ['</html>', 'Sample line item', 'data:image/png;base64', 'Discount (10%)', '-$150.00']),
            'preview' => $this->assertChannelContains($output, ['Sample line item', 'data:image/png;base64', 'Discount (10%)', '-$150.00']),
            // Email is a summary (totals only, no per-line breakdown), so we
            // verify the schema.org payload + the displayed total instead.
            'email' => $this->assertChannelContains($output, ['schema.org', '$1,500.00']),
            default => self::fail('Unknown channel: ' . $channel),
        };
    }

    /**
     * @param list<string> $needles
     */
    private function assertChannelContains(string $output, array $needles): void
    {
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $output);
        }
    }

    #[DataProvider('pdfTemplateProvider')]
    public function testPdfTemplateGenerates(string $slug): void
    {
        $generator = self::getContainer()->get(Generator::class);
        self::assertInstanceOf(Generator::class, $generator);

        if (! $generator->canPrintPdf()) {
            self::markTestSkipped('PDF generation requires mbstring + gd extensions.');
        }

        $invoice = $this->createFixtureInvoice();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render(
            sprintf('@AugiasInvoice/Templates/%s/pdf.html.twig', $slug),
            ['invoice' => $invoice]
        );

        $pdf = $generator->generate($html);
        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pdfTemplateProvider(): iterable
    {
        foreach (self::slugs() as $slug) {
            yield $slug => [$slug];
        }
    }

    /**
     * A 1x1 transparent PNG so every template's guarded logo block renders.
     */
    private function seedCompanyLogo(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $setting = $em->getRepository(Setting::class)->findOneBy(['key' => 'system/company/logo']);
        self::assertInstanceOf(Setting::class, $setting, 'system/company/logo setting not seeded');

        $setting->setValue('png|iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $em->flush();
    }

    private function createFixtureInvoice(): Invoice
    {
        $this->seedCompanyLogo();

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
            // Every design shows a discount (06/10/2026: four previews had no row for it).
            'discount' => new Discount()
                ->setType(Discount::TYPE_PERCENTAGE)
                ->setValuePercentage(10.0),
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
