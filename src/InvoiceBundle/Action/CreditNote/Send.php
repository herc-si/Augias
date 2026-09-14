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

use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Response\FlashResponse;
use Augias\CoreBundle\Traits\SaveableTrait;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Carbon\CarbonImmutable;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Sends a credit note to the client, issuing it on the way out if it is still
 * a draft — handing the document over is exactly what makes it final.
 */
final class Send
{
    use SaveableTrait;

    public function __construct(
        private readonly WorkflowInterface $creditNoteStateMachine,
        private readonly MailerInterface $mailer,
        private readonly RouterInterface $router,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, CreditNote $creditNote): RedirectResponse
    {
        $route = $this->router->generate('_credit_notes_view', ['id' => $creditNote->getId()]);

        if ($this->emailVerificationGate->isGated()) {
            return $this->flash($route, FlashResponse::FLASH_ERROR, 'email_verification.flash.send_invoice');
        }

        if ($this->creditNoteStateMachine->can($creditNote, CreditNoteGraph::TRANSITION_ISSUE)) {
            $this->creditNoteStateMachine->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
            $creditNote->setIssuedAt(CarbonImmutable::now());
            $this->save($creditNote);
        }

        try {
            $this->mailer->send(new CreditNoteEmail($creditNote));
        } catch (TransportExceptionInterface $exception) {
            // The document is issued either way: it has a number and the client
            // is owed the amount. Only the delivery failed, and saying so is
            // more useful than pretending the whole action did.
            $this->logger->error('Could not send credit note {id}: {message}', [
                'id' => $creditNote->getCreditNoteId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return $this->flash($route, FlashResponse::FLASH_ERROR, 'credit_note.send.failed');
        }

        return $this->flash($route, FlashResponse::FLASH_SUCCESS, 'credit_note.send.success');
    }

    private function flash(string $route, string $type, string $message): RedirectResponse
    {
        return new class($route, $type, $message) extends RedirectResponse implements FlashResponse {
            public function __construct(
                string $route,
                private readonly string $type,
                private readonly string $message,
            ) {
                parent::__construct($route);
            }

            public function getFlash(): Generator
            {
                yield $this->type => $this->message;
            }
        };
    }
}
