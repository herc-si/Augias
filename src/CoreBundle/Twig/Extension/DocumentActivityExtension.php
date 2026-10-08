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

namespace Augias\CoreBundle\Twig\Extension;

use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\CoreBundle\Journal\Journalled;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Override;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function in_array;

final class DocumentActivityExtension extends AbstractExtension
{
    /**
     * What a badge says, strongest first: an answer outweighs a reading,
     * which outweighs a sending.
     *
     * @var array<string, list<DocumentActivityType>>
     */
    private const array PRECEDENCE = [
        'accepted' => [DocumentActivityType::ClientAccepted],
        'declined' => [DocumentActivityType::ClientDeclined],
        'seen' => [DocumentActivityType::Viewed, DocumentActivityType::Downloaded],
        'sent' => [DocumentActivityType::Sent],
        'failed' => [DocumentActivityType::SendFailed],
    ];

    public function __construct(
        private readonly DocumentActivityRepository $repository,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_activity', $this->history(...)),
            new TwigFunction('document_activity_badge', $this->badge(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    /**
     * @return list<DocumentActivity>
     */
    public function history(Journalled $document): array
    {
        $id = $document->getId();

        if (! $id instanceof Ulid) {
            return [];
        }

        return $this->repository->forDocument($document->journalKind(), $id);
    }

    /**
     * Where a document stands with its client, in one badge: the client's
     * answer if they gave one, else when they last opened it, else when it
     * was last sent, else that sending failed — a failure shows only while
     * nothing has left — and, for a finalised document nobody sent, that it
     * never was.
     */
    public function badge(Environment $twig, Journalled $document): string
    {
        $id = $document->getId();
        $entries = $id instanceof Ulid ? $this->repository->clientSideFor($document->journalKind(), $id) : [];

        $state = null;
        $at = null;

        foreach (self::PRECEDENCE as $candidate => $types) {
            foreach ($entries as $entry) {
                if (in_array($entry->getType(), $types, true) && ! $entry->isLikelyAutomated()) {
                    $state = $candidate;
                    $at = $entry->getOccurredAt();

                    break 2;
                }
            }
        }

        if (null === $state && $this->awaitsTheClient($document)) {
            $state = 'never_sent';
        }

        return $twig->render('@AugiasCore/DocumentActivity/_badge.html.twig', ['state' => $state, 'at' => $at]);
    }

    /**
     * Finalised, so meant for the client: a pending quote, a pending or
     * overdue invoice, an issued credit note.
     */
    private function awaitsTheClient(Journalled $document): bool
    {
        return match (true) {
            $document instanceof Quote => QuoteStatus::Pending === $document->getStatus(),
            $document instanceof CreditNote => CreditNoteStatus::Issued === $document->getStatus(),
            $document instanceof Invoice => in_array($document->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true),
            default => false,
        };
    }
}
