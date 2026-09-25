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

namespace Augias\ElectronicInvoicingBundle\Action;

use Augias\CoreBundle\Response\FlashResponse;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Entity\Invoice;
use Generator;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use function str_starts_with;

/**
 * @see \Augias\ElectronicInvoicingBundle\Tests\Action\SendElectronicInvoiceTest
 */
final class SendElectronicInvoice
{
    public function __construct(
        private readonly ElectronicInvoiceManagerInterface $manager,
        private readonly RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request, Invoice $invoice): RedirectResponse
    {
        $route = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);

        try {
            $submission = $this->manager->send($invoice);
        } catch (LogicException) {
            return new class($route) extends RedirectResponse implements FlashResponse {
                public function getFlash(): Generator
                {
                    yield FlashResponse::FLASH_ERROR => 'einvoicing.send.no_active_provider';
                }
            };
        }

        if (! $submission->isSuccess()) {
            // A reason Augias itself worked out — an invoice of disbursements
            // alone, an account the platform has not verified — is a
            // translation key and says more than "failed". The platform's own
            // messages stay on the invoice page, where there is room for them.
            $message = (string) $submission->getMessage();
            $reason = str_starts_with($message, 'einvoicing.') ? $message : 'einvoicing.send.failed';

            return new class($route, $reason) extends RedirectResponse implements FlashResponse {
                public function __construct(
                    string $url,
                    private readonly string $reason
                ) {
                    parent::__construct($url);
                }

                public function getFlash(): Generator
                {
                    yield FlashResponse::FLASH_ERROR => $this->reason;
                }
            };
        }

        return new class($route) extends RedirectResponse implements FlashResponse {
            public function getFlash(): Generator
            {
                yield FlashResponse::FLASH_SUCCESS => 'einvoicing.send.success';
            }
        };
    }
}
