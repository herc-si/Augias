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

namespace Augias\CoreBundle\Listener;

use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use function in_array;

/**
 * Puts in a document's history each step of its life — published, accepted,
 * paid, cancelled — with who took it.
 */
final readonly class DocumentStatusActivityListener
{
    /**
     * Steps that say nothing the history does not already say better:
     * sending is recorded when the email actually leaves, with its addresses,
     * and editing a draft is a step from draft to draft on every save.
     */
    private const array SILENT = ['send', 'edit'];

    /**
     * Answers a client gives from their link: recorded there, with their name
     * or their reason, rather than here as a step nobody took.
     */
    private const array ANSWERED_BY_CLIENT = ['accept', 'decline'];

    public function __construct(
        private DocumentActivityRecorder $recorder,
        private Security $security,
    ) {
    }

    /**
     * @param CompletedEvent<object> $event
     */
    #[AsEventListener(event: 'workflow.quote.completed')]
    #[AsEventListener(event: 'workflow.invoice.completed')]
    #[AsEventListener(event: 'workflow.credit_note.completed')]
    public function onCompleted(CompletedEvent $event): void
    {
        $document = $event->getSubject();
        $transition = $event->getTransition()?->getName();

        if (null === $transition || in_array($transition, self::SILENT, true)) {
            return;
        }

        if (null === $this->security->getUser() && in_array($transition, self::ANSWERED_BY_CLIENT, true)) {
            return;
        }

        if (! $document instanceof Quote && ! $document instanceof Invoice && ! $document instanceof CreditNote) {
            return;
        }

        $this->recorder->record($document, $document->getCompany(), DocumentActivityType::Status, $transition);
    }
}
