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

use Augias\CoreBundle\Templates\PreviewInvoiceFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;
use function dirname;
use function file_get_contents;

/**
 * Every design shows the document's subject above its lines, and "Studio"
 * no longer makes a project title up from the client's name: it read
 * "toto mission" on an invoice to a client called toto (12/10/2026).
 */
final class DocumentSubjectTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentTemplates(): iterable
    {
        $root = dirname(__DIR__, 3);
        $finder = new Finder()->files()->in([
            $root . '/InvoiceBundle/Resources/views/Templates',
            $root . '/QuoteBundle/Resources/views/Templates',
        ])->name(['pdf.html.twig', 'preview.html.twig']);

        foreach ($finder as $file) {
            yield $file->getRelativePathname() . ' ' . $file->getRealPath() => [(string) $file->getRealPath()];
        }

        yield 'default invoice PDF' => [$root . '/InvoiceBundle/Resources/views/Pdf/invoice.html.twig'];
        yield 'default invoice page' => [$root . '/InvoiceBundle/Resources/views/external_invoice_view.html.twig'];
        yield 'default quote PDF' => [$root . '/QuoteBundle/Resources/views/Pdf/quote.html.twig'];
        yield 'default quote page' => [$root . '/QuoteBundle/Resources/views/quote_template.html.twig'];
        yield 'credit note PDF' => [$root . '/InvoiceBundle/Resources/views/CreditNote/pdf.html.twig'];
    }

    #[DataProvider('documentTemplates')]
    public function testEveryDesignShowsTheSubject(string $template): void
    {
        self::assertMatchesRegularExpression('/\.subject\b/', (string) file_get_contents($template));
    }

    public function testStudioTitlesTheProjectWithTheSubjectOnly(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $invoice = self::getContainer()->get(PreviewInvoiceFactory::class)->create();
        $client = (string) $invoice->getClient();

        $invoice->setSubject('Migration vers Odoo');
        $html = $twig->render('@AugiasInvoice/Templates/studio/pdf.html.twig', ['invoice' => $invoice]);
        self::assertStringContainsString('Migration vers Odoo', $html);

        $invoice->setSubject(null);
        $html = $twig->render('@AugiasInvoice/Templates/studio/pdf.html.twig', ['invoice' => $invoice]);
        self::assertStringNotContainsString($client . ' engagement', $html);
        self::assertStringNotContainsString($client . ' mission', $html);
        self::assertStringNotContainsString('Project', $html, 'No subject, no project block.');
    }

    public function testTheDefaultPdfShowsTheSubject(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $invoice = self::getContainer()->get(PreviewInvoiceFactory::class)->create();
        $invoice->setSubject('Migration vers Odoo');

        self::assertStringContainsString('Migration vers Odoo', $twig->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $invoice]));
    }
}
