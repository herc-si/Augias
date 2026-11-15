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

use Augias\InvoiceBundle\Deposit\DepositBilling;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceClientMode;
use Augias\InvoiceBundle\Form\Type\InvoiceType;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\SaasBundle\Feature\Feature;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use function str_replace;
use function trim;

/**
 * "Bill a deposit" on an accepted quote: the invoice form, filled with a
 * deposit invoice for the share asked for. Like the invoice made from a
 * quote, nothing is saved and no number is taken until the form is.
 */
final class CreateDeposit extends AbstractController
{
    public function __construct(
        private readonly DepositBilling $deposits,
        private readonly InvoiceFormManager $formManager,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly FeatureGate $featureGate,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Quote $quote): Response
    {
        $back = $this->redirectToRoute('_quotes_view', ['id' => $quote->getId()]);

        if (QuoteStatus::Accepted !== $quote->getStatus()) {
            $this->addFlash('warning', 'quote.create_invoice.not_accepted');

            return $back;
        }

        // Once the final invoice exists, everything left has been billed.
        if ($quote->getInvoice() instanceof Invoice) {
            $this->addFlash('warning', 'invoice.deposit.flash.final_exists');

            return $back;
        }

        try {
            // "33,33" as a French keyboard types it.
            $percentage = BigDecimal::of(str_replace(',', '.', trim((string) $request->query->get('percentage', ''))));
        } catch (MathException) {
            $percentage = BigDecimal::zero();
        }

        $remaining = $this->deposits->remainingPercentage($quote);

        if (! $percentage->isPositive() || $percentage->isGreaterThan($remaining)) {
            $this->addFlash('danger', $this->translator->trans('invoice.deposit.flash.percentage', ['%remaining%' => str_replace('.', ',', (string) $remaining->strippedOfTrailingZeros())]));

            return $back;
        }

        if (! $this->featureGate->canUse(
            Feature::InvoicesPerMonth->value,
            $this->invoiceRepository->countCreatedInMonth($this->clock->now()),
        )) {
            return $this->render('@AugiasInvoice/Default/invoice_gated.html.twig');
        }

        $dto = $this->formManager->createDTOFromInvoice($this->deposits->draft($quote, $percentage));
        $dto->clientMode = InvoiceClientMode::Existing;

        $form = $this->createForm(InvoiceType::class, $dto, ['currency' => $quote->getClient()->getCurrency()]);

        return $this->render('@AugiasInvoice/Default/create.html.twig', [
            'dto' => $dto,
            'form' => $form,
            'recurring' => false,
            'depositForQuote' => $quote,
        ]);
    }
}
