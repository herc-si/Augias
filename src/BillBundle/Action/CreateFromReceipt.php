<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\BillBundle\Action;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Manager\BillManager;
use Augias\BillBundle\Repository\BillRepository;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The single entry point behind the "Create Bill" row action on the
 * received-invoices grid — idempotent: a receipt that already has a linked
 * {@see Bill} (from a previous click) is opened rather than duplicated.
 */
final readonly class CreateFromReceipt
{
    public function __construct(
        private BillRepository $billRepository,
        private BillManager $billManager,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(ElectronicInvoiceReceipt $receipt, Request $request): RedirectResponse
    {
        $bill = $this->billRepository->findOneBy(['electronicInvoiceReceipt' => $receipt]);

        // A credit note is not a purchase to pay (CreateBillFromReceiptListener).
        if (! $bill instanceof Bill && $receipt->isCreditNote()) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', 'bill.flash.credit_note_not_a_bill');
            }

            return new RedirectResponse($this->router->generate('_einvoicing_incoming'));
        }

        if (! $bill instanceof Bill) {
            $bill = $this->billManager->createFromReceipt($receipt);
        }

        return new RedirectResponse($this->router->generate('_bills_view', ['id' => $bill->getId()]));
    }
}
