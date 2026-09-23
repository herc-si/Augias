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
use Augias\InvoiceBundle\Email\InvoiceEmail;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * Sends the supplier's documents with the invoice that re-bills them.
 *
 * A disbursement is only outside turnover if the client receives the
 * supplier's invoice made out in their name along with the re-billing (CGI art.
 * 267-II-2°). The e-mail is how the invoice reaches them, so that is where the
 * receipts go too.
 */
final readonly class DisbursementReceiptListener implements EventSubscriberInterface
{
    public function __construct(
        private DocumentStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof InvoiceEmail) {
            return;
        }

        foreach ($message->getInvoice()->getLines() as $line) {
            if (! $line->isDisbursement()) {
                continue;
            }

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
