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

namespace Augias\InvoiceBundle\Document;

use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\InvoiceBundle\Entity\Invoice;
use Mpdf\MpdfException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use function sprintf;

/**
 * Renders the disbursement note that goes out beside an invoice.
 *
 * One place for the page, the client's link and the e-mail, so the three
 * cannot disagree about what the note says.
 */
final readonly class DisbursementNoteRenderer
{
    public const string TEMPLATE = '@AugiasInvoice/Pdf/disbursement_note.html.twig';

    public function __construct(
        private Generator $generator,
        private Environment $twig,
    ) {
    }

    public function canRender(Invoice $invoice): bool
    {
        return $invoice->hasDisbursements() && $this->generator->canPrintPdf();
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function render(Invoice $invoice): string
    {
        return $this->generator->generate($this->twig->render(self::TEMPLATE, ['invoice' => $invoice]));
    }

    public function filename(Invoice $invoice): string
    {
        return sprintf('%s.pdf', $invoice->getDisbursementNoteId());
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function response(Invoice $invoice): PdfResponse
    {
        if (! $this->canRender($invoice)) {
            throw new NotFoundHttpException('This invoice has no disbursement note.');
        }

        return new PdfResponse($this->render($invoice), $this->filename($invoice));
    }
}
