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

namespace Augias\InvoiceBundle\Action\Transition;

use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Response\FlashResponse;
use Augias\CoreBundle\Traits\SaveableTrait;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Model\Graph;
use Generator;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * @see \Augias\InvoiceBundle\Tests\Action\Transition\SendTest
 */
final class Send
{
    use SaveableTrait;

    public function __construct(
        private readonly WorkflowInterface $invoiceStateMachine,
        private readonly MailerInterface $mailer,
        private readonly RouterInterface $router,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly LoggerInterface $logger,
        private readonly ElectronicInvoiceManagerInterface $electronicInvoiceManager,
    ) {
    }

    public function __invoke(Request $request, Invoice $invoice): RedirectResponse
    {
        $route = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);

        if ($this->emailVerificationGate->isGated()) {
            return new class($route) extends RedirectResponse implements FlashResponse {
                public function getFlash(): Generator
                {
                    yield FlashResponse::FLASH_ERROR => 'email_verification.flash.send_invoice';
                }
            };
        }

        if ($invoice->getUsers()->isEmpty()) {
            return new class($route) extends RedirectResponse implements FlashResponse {
                public function getFlash(): Generator
                {
                    yield FlashResponse::FLASH_ERROR => 'invoice.send.no_recipients';
                }
            };
        }

        if (InvoiceStatus::Pending !== $invoice->getStatus() && $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_ACCEPT)) {
            $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
        }

        $this->save($invoice);

        $emailSent = true;

        try {
            $this->mailer->send(new InvoiceEmail($invoice));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to send invoice email: ' . $e->getMessage(), ['exception' => $e]);
            $emailSent = false;
        }

        // The email and the electronic invoice are two deliveries of the same
        // invoice: a mail server down must not keep it off the platform, where
        // it is the copy that counts for the tax administration.
        $electronicInvoiceFlash = $this->sendElectronicInvoiceIfEligible($invoice);

        return new class($route, $emailSent, $electronicInvoiceFlash) extends RedirectResponse implements FlashResponse {
            /**
             * @param array{string, string}|null $electronicInvoiceFlash
             */
            public function __construct(
                string $url,
                private readonly bool $emailSent,
                private readonly ?array $electronicInvoiceFlash,
            ) {
                parent::__construct($url);
            }

            public function getFlash(): Generator
            {
                if ($this->emailSent) {
                    yield FlashResponse::FLASH_SUCCESS => 'invoice.transition.action.sent';
                } else {
                    yield FlashResponse::FLASH_ERROR => 'invoice.email.send_failed';
                }

                if ($this->electronicInvoiceFlash !== null) {
                    yield $this->electronicInvoiceFlash[0] => $this->electronicInvoiceFlash[1];
                }
            }
        };
    }

    /**
     * Electronic invoicing only applies to eligible clients (setting enabled, an active
     * provider, client has a SIRET or a SIREN) — publishing an invoice for anyone else is unaffected.
     * A failure here must not undo the invoice having already been published and emailed,
     * so it only adds a second flash rather than changing the response.
     *
     * @return array{string, string}|null
     */
    private function sendElectronicInvoiceIfEligible(Invoice $invoice): ?array
    {
        if (! $this->electronicInvoiceManager->isEligible($invoice)) {
            return null;
        }

        try {
            $submission = $this->electronicInvoiceManager->send($invoice);
        } catch (LogicException $e) {
            $this->logger->error('Failed to send electronic invoice: ' . $e->getMessage(), ['exception' => $e]);

            return [FlashResponse::FLASH_ERROR, 'einvoicing.send.failed'];
        }

        if ($submission->isSuccess()) {
            return [FlashResponse::FLASH_SUCCESS, 'einvoicing.send.success'];
        }

        return [
            FlashResponse::FLASH_ERROR,
            $submission->getMessage() === ElectronicInvoiceManager::ONLY_DISBURSEMENTS ? ElectronicInvoiceManager::ONLY_DISBURSEMENTS : 'einvoicing.send.failed',
        ];
    }
}
