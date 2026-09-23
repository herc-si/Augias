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

namespace Augias\InvoiceBundle\Action\DisbursementNote;

use Augias\InvoiceBundle\Document\DisbursementNoteRenderer;
use Augias\InvoiceBundle\Entity\Invoice;
use Mpdf\MpdfException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * The disbursement note of an invoice, for the user.
 */
final readonly class View
{
    public function __construct(
        private DisbursementNoteRenderer $renderer,
    ) {
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function __invoke(Invoice $invoice): Response
    {
        return $this->renderer->response($invoice);
    }
}
