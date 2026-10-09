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

namespace Augias\InvoiceBundle\Action;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Service\CreditNoteApplier;
use Augias\PaymentBundle\Repository\PaymentRepository;
use Mpdf\MpdfException;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * @see \Augias\InvoiceBundle\Tests\Action\ViewTest
 */
final readonly class View
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private Generator $pdfGenerator,
        private Environment $twig,
        private BillingTemplateResolver $templateResolver,
        private ElectronicInvoiceManagerInterface $electronicInvoiceManager,
        private ?CreditNoteApplier $creditNoteApplier = null,
    ) {
    }

    /**
     * @return array{invoice: Invoice, payments: array<string, mixed>, documentTemplate: string|null, showElectronicInvoiceAction: bool}|Response
     * @throws LoaderError
     * @throws MpdfException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    #[Template('@AugiasInvoice/Default/view.html.twig')]
    public function __invoke(Request $request, Invoice $invoice): array | Response
    {
        if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
            return new PdfResponse($this->pdfGenerator->generate($this->twig->render($this->templateResolver->resolve($invoice, BillingTemplateChannel::Pdf), ['invoice' => $invoice])), sprintf('invoice_%s.pdf', $invoice->getInvoiceId()));
        }

        $client = $invoice->getClient();

        return [
            'invoice' => $invoice,
            'payments' => $this->paymentRepository->getPaymentsForInvoice($invoice),
            'documentTemplate' => $this->templateResolver->customTemplate($invoice, BillingTemplateChannel::View),
            'showElectronicInvoiceAction' => $this->electronicInvoiceManager->isEligible($invoice),
            // What the client's open credit notes could still take off it.
            'availableCredit' => $client instanceof Client && $this->creditNoteApplier instanceof CreditNoteApplier ? (string) $this->creditNoteApplier->available($client) : '0',
        ];
    }
}
