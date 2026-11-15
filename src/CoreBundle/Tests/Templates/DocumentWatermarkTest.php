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
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Environment;
use function dirname;
use function file_get_contents;

/**
 * The status watermark across a PDF is read by the client: it is written in
 * the document's language, not as the raw status code. A French invoice went
 * out with "PENDING" across the page (15/11/2026).
 */
final class DocumentWatermarkTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pdfTemplates(): iterable
    {
        $root = dirname(__DIR__, 3);
        $finder = new Finder()->files()->in([
            $root . '/InvoiceBundle/Resources/views',
            $root . '/QuoteBundle/Resources/views',
        ])->name('*.twig')->contains('<watermarktext');

        foreach ($finder as $file) {
            yield $file->getRealPath() => [(string) $file->getRealPath()];
        }
    }

    #[DataProvider('pdfTemplates')]
    public function testNoWatermarkPrintsTheRawStatusCode(string $template): void
    {
        $source = (string) file_get_contents($template);

        self::assertDoesNotMatchRegularExpression('/<watermarktext content="\{\{\s*\w+\.status\.value\s*\|/', $source);
        self::assertMatchesRegularExpression('/<watermarktext content="\{\{[^}]*\|trans\b/', $source);
    }

    public function testTheWatermarkOfAFrenchInvoiceIsInFrench(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale('fr');

        $invoice = self::getContainer()->get(PreviewInvoiceFactory::class)->create();
        $invoice->setStatus(InvoiceStatus::Pending);

        $html = self::getContainer()->get(Environment::class)->render('@AugiasInvoice/Pdf/invoice.html.twig', ['invoice' => $invoice]);

        self::assertStringContainsString('<watermarktext content="EN ATTENTE"', $html);
        self::assertStringNotContainsString('content="PENDING"', $html);
    }
}
