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

namespace Augias\InvoiceBundle\Action\CreditNote;

use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\InvoiceBundle\Entity\CreditNote;
use Mpdf\MpdfException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use function sprintf;

/**
 * @see \Augias\InvoiceBundle\Tests\Action\CreditNote\ViewTest
 */
final readonly class View
{
    public function __construct(
        private Generator $pdfGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * @return array{creditNote: CreditNote}|Response
     * @throws LoaderError
     * @throws MpdfException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    #[Template('@AugiasInvoice/CreditNote/view.html.twig')]
    public function __invoke(Request $request, CreditNote $creditNote): array | Response
    {
        // No per-company template override yet, unlike invoices and quotes: the
        // resolver is keyed on those two document types, and a credit note has
        // no custom template to resolve to.
        if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
            return new PdfResponse(
                $this->pdfGenerator->generate(
                    $this->twig->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote]),
                ),
                sprintf('credit_note_%s.pdf', $creditNote->getCreditNoteId()),
            );
        }

        return ['creditNote' => $creditNote];
    }
}
