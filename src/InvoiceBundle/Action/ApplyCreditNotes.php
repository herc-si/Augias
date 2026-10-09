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
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Service\CreditNoteApplier;
use Augias\MoneyBundle\Formatter\MoneyFormatterInterface;
use Brick\Math\Exception\MathException;
use Money\Currency;
use Money\Money;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function assert;
use function in_array;

/**
 * "Utiliser les avoirs": sets the client's open credit notes against this
 * invoice, for at most what it still owes.
 */
final readonly class ApplyCreditNotes
{
    public function __construct(
        private CreditNoteApplier $applier,
        private RouterInterface $router,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private MoneyFormatterInterface $formatter,
    ) {
    }

    /**
     * @throws MathException
     */
    public function __invoke(Request $request, Invoice $invoice): RedirectResponse
    {
        $session = $request->getSession();
        assert($session instanceof Session);

        // From the payment page, back to it to pay the rest; done there or
        // from the invoice page, to the invoice.
        $fromPayment = 'payment' === $request->request->get('_return');
        $response = new RedirectResponse($this->router->generate('_invoices_view', ['id' => $invoice->getId()]));

        if (! $this->csrf->isTokenValid(new CsrfToken('apply_credit_notes' . $invoice->getId(), (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'invoice.credit_notes.invalid_token');

            return $response;
        }

        if (! in_array($invoice->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)) {
            $session->getFlashBag()->add('warning', 'invoice.credit_notes.nothing_owed');

            return $response;
        }

        try {
            $applied = $this->applier->applyTo($invoice);
        } catch (AllocationException $exception) {
            $session->getFlashBag()->add('danger', $exception->getMessage());

            return $response;
        }

        if ($applied->isZero()) {
            $session->getFlashBag()->add('warning', 'invoice.credit_notes.none_available');

            return $response;
        }

        $currency = $invoice->getClient()?->getCurrency() ?? new Currency('EUR');
        $amount = $this->formatter->format(new Money((string) $applied->toScale(0), $currency));

        $session->getFlashBag()->add('success', $this->translator->trans('invoice.credit_notes.applied', ['%amount%' => $amount]));

        if ($fromPayment && in_array($invoice->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true)) {
            return new RedirectResponse($this->router->generate('_payments_create', ['uuid' => $invoice->getUuid()]));
        }

        return $response;
    }
}
