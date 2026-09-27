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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use function dirname;
use function preg_match;
use function preg_match_all;

/**
 * The public page of an invoice or a quote is opened on a phone more often
 * than not. Below the sm breakpoint _document-templates.scss turns each line
 * into a card whose figures are labelled from the cells' data-label: a cell
 * left without one would show a bare number.
 */
final class DocumentTemplatesPhoneLayoutTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicViews(): iterable
    {
        $root = dirname(__DIR__, 3);
        $finder = new Finder()->files()->in([
            $root . '/InvoiceBundle/Resources/views/Templates',
            $root . '/QuoteBundle/Resources/views/Templates',
        ])->name('preview.html.twig');

        foreach ($finder as $file) {
            yield $file->getRelativePath() . ' ' . $file->getRealPath() => [(string) $file->getRealPath()];
        }

        yield 'default invoice' => [$root . '/InvoiceBundle/Resources/views/external_invoice_view.html.twig'];
        yield 'default quote' => [$root . '/QuoteBundle/Resources/views/quote_template.html.twig'];
    }

    #[DataProvider('publicViews')]
    public function testEveryLineFigureIsLabelled(string $template): void
    {
        $source = (string) file_get_contents($template);

        self::assertSame(1, preg_match('/<table class="[^"]*\bdoc-lines\b.*?<\/table>/s', $source, $table), 'The line table carries the doc-lines class.');

        preg_match_all('/<td\b([^>]*)>/', $table[0], $cells);
        self::assertNotEmpty($cells[1]);

        $described = 0;
        foreach ($cells[1] as $attributes) {
            if (str_contains($attributes, 'doc-lines-description')) {
                ++$described;

                continue;
            }

            if (! str_contains($table[0], '<thead')) {
                continue;
            }

            self::assertTrue(
                str_contains($attributes, 'data-label=') || str_contains($attributes, 'doc-lines-index'),
                'A figure without data-label shows a bare value on a phone: <td' . $attributes . '>',
            );
        }

        self::assertSame(1, $described, 'The description cell is marked, it heads the card on a phone.');
    }

    #[DataProvider('publicViews')]
    public function testNoColumnIsHalfTheScreenAtEveryWidth(string $template): void
    {
        self::assertDoesNotMatchRegularExpression('/class="col-6\b/', (string) file_get_contents($template));
    }
}
