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

namespace Augias\InvoiceBundle\Tests\Templates;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function sprintf;
use function str_contains;

/**
 * A document that totals an invoice may not pass over its disbursements.
 *
 * A disbursement is money advanced in the client's name: it is owed, it is in
 * the grand total, and it is outside the taxable base. A document that shows
 * the fees and the total without saying that some of it was advanced states a
 * turnover figure that is not one — and, under CGI art. 267-II-2°, the exclusion
 * from the base only holds if the invoice says on what ground it applies.
 *
 * Asked of the source rather than by rendering, for the reason
 * {@see \Augias\PaymentBundle\Tests\Templates\PaymentMethodIsNeverAssumedTest}
 * gives: reaching every one of these designs with a fixture that has a
 * disbursement is fragile, and what is worth pinning is the omission itself.
 *
 * There are nine designs and several channels, and a tenth will be added by
 * someone who is thinking about typography. This is what tells them.
 */
#[CoversNothing]
final class DisbursementsAreNeverSilentTest extends TestCase
{
    /**
     * A template that reads the fee subtotal off an invoice is rendering that
     * invoice's totals, so it has to account for the disbursements too — either
     * itself, or by handing the job to the shared totals macro.
     */
    public function testEveryTemplateThatTotalsAnInvoiceAccountsForDisbursements(): void
    {
        $offenders = [];

        foreach ($this->twigFilesIn(dirname(__DIR__, 2) . '/Resources/views') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'invoice.baseTotal')) {
                continue;
            }

            if (
                str_contains($source, 'invoice.disbursementTotal')
                || str_contains($source, 'invoice.hasDisbursements')
                || str_contains($source, 'totals_block')
            ) {
                continue;
            }

            $offenders[] = $file->getFilename();
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "These templates show an invoice's subtotal but never its disbursements: %s.\n"
                . 'Add the separate total and the art. 267-II-2° wording, or render the totals through '
                . 'the totals_block macro, which carries both.',
                implode(', ', $offenders),
            ),
        );
    }

    /**
     * The wording itself, wherever a document carries the separate total. A
     * figure on its own does not make the operation a disbursement; the ground
     * it rests on is what does, and that has to be printed.
     */
    public function testEveryDocumentShowingTheTotalAlsoCarriesTheWording(): void
    {
        $offenders = [];

        foreach ($this->twigFilesIn(dirname(__DIR__, 2) . '/Resources/views') as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'invoice.disbursementTotal')) {
                continue;
            }

            // The e-mail is a summary with a link to the document, not the
            // document — the wording belongs on what the client keeps. A
            // recurring invoice is never sent either: it is the pattern the
            // invoices are cut from, and each of those carries the wording.
            $exempt = ['_email_base.html.twig', 'CreateRecurringInvoice.html.twig', 'view_recurring.html.twig'];

            if (str_contains($file->getPathname(), '/Email/') || in_array($file->getFilename(), $exempt, true)) {
                continue;
            }

            if (str_contains($source, 'invoice.disbursement.mention')) {
                continue;
            }

            $offenders[] = $file->getFilename();
        }

        self::assertSame([], $offenders, sprintf(
            'These templates print a disbursement total with no wording to justify it: %s.',
            implode(', ', $offenders),
        ));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function twigFilesIn(string $directory): iterable
    {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                yield $file;
            }
        }
    }
}
