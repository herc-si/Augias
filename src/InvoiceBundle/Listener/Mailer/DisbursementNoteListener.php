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

namespace Augias\InvoiceBundle\Listener\Mailer;

use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\InvoiceBundle\Document\DisbursementNoteRenderer;
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Mpdf\MpdfException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Sends the disbursement note, and the supplier's documents behind it, with
 * the invoice.
 *
 * The invoice no longer carries the disbursements — they are on a note of
 * their own, which never goes through an e-invoicing platform — so the e-mail
 * is how the note reaches the client. And a disbursement is only outside
 * turnover if the client receives the supplier's invoice made out in their
 * name along with the re-billing (CGI art. 267-II-2°), so the receipts go
 * too.
 */
final readonly class DisbursementNoteListener implements EventSubscriberInterface
{
    public function __construct(
        private DocumentStorage $storage,
        private DisbursementNoteRenderer $noteRenderer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof InvoiceEmail) {
            return;
        }

        $invoice = $message->getInvoice();

        if ($this->noteRenderer->canRender($invoice)) {
            $message->attach($this->noteRenderer->render($invoice), $this->noteRenderer->filename($invoice), 'application/pdf');
        }

        foreach ($invoice->getDisbursementLines() as $line) {
            foreach ($line->getReceipts() as $receipt) {
                try {
                    $path = $this->storage->path($receipt->getStoragePath());
                } catch (RuntimeException $exception) {
                    // A file lost from disk should not stop the invoice itself
                    // from going out; it is logged so that someone notices the
                    // receipt the client did not get.
                    $this->logger->error('A disbursement receipt could not be attached to the invoice e-mail.', [
                        'receipt' => (string) $receipt->getId(),
                        'exception' => $exception,
                    ]);

                    continue;
                }

                $message->attachFromPath($path, $receipt->getFilename(), $receipt->getMimeType());
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => '__invoke',
        ];
    }
}
