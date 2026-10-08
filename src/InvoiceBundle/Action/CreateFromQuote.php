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

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Manager\InvoiceManager;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\SaasBundle\Feature\Feature;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Create the invoice" on an accepted quote: the invoice form, filled with
 * the quote's client, contacts, lines, discount and terms. Nothing is saved
 * and no number is taken until the form is: accepting a quote used to create
 * a numbered invoice straight away, weeks before the work was invoiced
 * (08/10/2026).
 */
final class CreateFromQuote extends AbstractController
{
    public function __construct(
        private readonly InvoiceManager $invoiceManager,
        private readonly InvoiceFormManager $formManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly FeatureGate $featureGate,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Quote $quote): Response
    {
        if ($quote->getInvoice() instanceof Invoice) {
            $this->addFlash('warning', 'quote.create_invoice.already');

            return $this->redirectToRoute('_invoices_view', ['id' => $quote->getInvoice()->getId()]);
        }

        if (QuoteStatus::Accepted !== $quote->getStatus()) {
            $this->addFlash('warning', 'quote.create_invoice.not_accepted');

            return $this->redirectToRoute('_quotes_view', ['id' => $quote->getId()]);
        }

        if (! $this->featureGate->canUse(
            Feature::InvoicesPerMonth->value,
            $this->invoiceRepository->countCreatedInMonth($this->clock->now()),
        )) {
            return $this->render('@AugiasInvoice/Default/invoice_gated.html.twig');
        }

        $dto = $this->formManager->createDTOFromInvoice($this->invoiceManager->draftFromQuote($quote));
        $dto->clientMode = InvoiceClientMode::Existing;

        $form = $this->createForm(InvoiceType::class, $dto, ['currency' => $quote->getClient()->getCurrency()]);

        return $this->render('@AugiasInvoice/Default/create.html.twig', [
            'dto' => $dto,
            'form' => $form,
            'recurring' => false,
            'fromQuote' => $quote,
        ]);
    }
}
