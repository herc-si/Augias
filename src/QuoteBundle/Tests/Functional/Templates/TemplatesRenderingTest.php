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

namespace Augias\QuoteBundle\Tests\Functional\Templates;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Pdf\Generator;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\QuoteBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\SettingsBundle\Entity\Setting;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function array_map;
use function basename;
use function dirname;
use function glob;
use function implode;
use function range;
use function sort;
use function sprintf;
use function str_starts_with;

/**
 * Renders every quote design template under `Resources/views/Templates` so a
 * newly added template directory is covered automatically.
 */
final class TemplatesRenderingTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private const array CHANNELS = ['pdf', 'email', 'preview'];

    /**
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

        self::assertNotEmpty($slugs, 'No quote design templates found');

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
        $quote = $this->createFixtureQuote();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $output = $twig->render(
            sprintf('@AugiasQuote/Templates/%s/%s.html.twig', $slug, $channel),
            ['quote' => $quote]
        );

        self::assertNotEmpty($output, sprintf('Template %s/%s produced empty output', $slug, $channel));
        self::assertStringContainsString($quote->getQuoteId(), $output);

        match ($channel) {
            // PDF and preview render every line item, the client block and the
            // company logo — assert all surface so a regression that drops
            // `{% for line in quote.lines %}` or the logo block is caught.
            'pdf' => $this->assertChannelContains($output, ['</html>', 'Sample line item', (string) $quote->getClient(), 'data:image/png;base64']),
            'preview' => $this->assertChannelContains($output, ['Sample line item', (string) $quote->getClient(), 'data:image/png;base64']),
            // Email is a summary addressed to the client (totals only, no
            // per-line breakdown or client block), so we verify the schema.org
            // payload + the displayed total instead.
            'email' => $this->assertChannelContains($output, ['schema.org', '$1,500.00']),
            default => self::fail('Unknown channel: ' . $channel),
        };
    }

    /**
     * Long terms in small print, two columns, kept with the totals: at full
     * size they spilled alone onto a second page (06/10/2026).
     */
    #[DataProvider('pdfTemplateProvider')]
    public function testLongTermsStayWithTheTotals(string $slug): void
    {
        $quote = $this->createFixtureQuote();
        $quote->setTerms(implode("\n", array_map(static fn (int $i): string => sprintf('Clause %d.', $i), range(1, 10))));

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render(sprintf('@AugiasQuote/Templates/%s/pdf.html.twig', $slug), ['quote' => $quote]);

        self::assertStringContainsString('page-break-inside: avoid', $html);

        // Split in two halves: the first clause in one column, the last in the other.
        self::assertMatchesRegularExpression('#Clause 1\.<br />.*?Clause 5\.</td>\s*<td[^>]*>Clause 6\.#s', $html);
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

        $quote = $this->createFixtureQuote();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render(
            sprintf('@AugiasQuote/Templates/%s/pdf.html.twig', $slug),
            ['quote' => $quote]
        );

        $pdf = $generator->generate($html);
        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * Too many lines for one page: the document runs onto a second one. Two
     * designs laid the whole page out in a table, whose cell cannot break, and
     * mPDF shrank everything onto one page in tiny print (08/10/2026).
     */
    #[DataProvider('pdfTemplateProvider')]
    public function testManyLinesRunOntoASecondPage(string $slug): void
    {
        $generator = self::getContainer()->get(Generator::class);
        self::assertInstanceOf(Generator::class, $generator);

        if (! $generator->canPrintPdf()) {
            self::markTestSkipped('PDF generation requires mbstring + gd extensions.');
        }

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render(
            sprintf('@AugiasQuote/Templates/%s/pdf.html.twig', $slug),
            ['quote' => $this->createFixtureQuote(40)]
        );

        // Unprotected, so the page objects can be counted in the file.
        $pdf = $generator->generate($html, false);

        self::assertGreaterThanOrEqual(2, preg_match_all('#/Type /Page\b(?!s)#', $pdf));
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

    private function createFixtureQuote(int $lineCount = 1): Quote
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

        return QuoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => QuoteStatus::Pending,
            'quoteId' => 'QUOTE-FIXTURE-001',
            'due' => CarbonImmutable::now()->addDays(14),
            'archived' => null,
            'terms' => 'Valid for 14 days.',
            'notes' => 'Thank you for your interest.',
            'total' => BigInteger::of(150000),
            'baseTotal' => BigInteger::of(150000),
            'tax' => BigInteger::of(0),
            'discount' => new Discount()
                ->setType(null),
            'lines' => array_map(
                static fn (): Line => new Line()
                    ->setDescription('Sample line item')
                    ->setPrice(BigInteger::of(75000))
                    ->setQty(2)
                    ->setTotal(BigInteger::of(150000)),
                range(1, $lineCount),
            ),
            'users' => [$contact],
        ]);
    }
}
