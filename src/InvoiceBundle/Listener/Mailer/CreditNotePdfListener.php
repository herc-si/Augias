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

use Augias\CoreBundle\Pdf\Generator;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Mpdf\MpdfException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Attaches the credit note itself, the same PDF the credit note page downloads.
 *
 * @see \Augias\InvoiceBundle\Tests\Listener\Mailer\CreditNotePdfListenerTest
 */
final readonly class CreditNotePdfListener implements EventSubscriberInterface
{
    public function __construct(
        private Generator $generator,
        private Environment $twig,
    ) {
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof CreditNoteEmail || ! $this->generator->canPrintPdf()) {
            return;
        }

        $creditNote = $message->getCreditNote();
        $content = $this->generator->generate(
            $this->twig->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote]),
        );

        $message->attach($content, sprintf('credit_note_%s.pdf', $creditNote->getCreditNoteId()), 'application/pdf');
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
