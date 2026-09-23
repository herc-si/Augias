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

namespace Augias\InvoiceBundle\Action\DisbursementReceipt;

use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Takes a receipt off its line — only while the invoice can still be edited.
 *
 * Once the invoice has gone out, the client may have received the document
 * with it; removing it then would leave the company unable to show what it
 * sent. A wrong file is replaced by adding the right one, not by rewriting
 * what the client was given.
 */
final readonly class Delete
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RouterInterface $router,
        private WorkflowInterface $invoiceStateMachine,
    ) {
    }

    public function __invoke(DisbursementReceipt $receipt, Request $request, Session $session): Response
    {
        $line = $receipt->getLine();
        $invoice = Upload::invoiceOf($line);
        $back = new RedirectResponse($this->router->generate('_invoices_view', ['id' => $invoice->getId()]));

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken('delete_disbursement_receipt' . $receipt->getId(), $request->request->getString('_token')))) {
            $session->getFlashBag()->add('danger', 'invoice.disbursement.receipt.flash.invalid_token');

            return $back;
        }

        if (! $this->invoiceStateMachine->can($invoice, 'edit')) {
            $session->getFlashBag()->add('warning', 'invoice.disbursement.receipt.flash.locked');

            return $back;
        }

        $entityManager = $this->doctrine->getManager();
        $line->removeReceipt($receipt);
        $entityManager->remove($receipt);
        $entityManager->flush();

        $session->getFlashBag()->add('success', 'invoice.disbursement.receipt.flash.deleted');

        return $back;
    }
}
