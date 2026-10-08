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

namespace Augias\DashboardBundle\Widgets;

use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Repository\DocumentActivityRepository;
use Augias\DashboardBundle\Attribute\AsDashboardWidget;
use Augias\DashboardBundle\Enum\WidgetZone;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * What happened between the company's documents and their clients, every
 * document together: sent, failed, opened, accepted, declined — the history
 * of each quote and invoice, gathered on the dashboard (08/10/2026).
 *
 * Each line names its document, which the history stores by kind and id:
 * they are fetched in one query per kind, not one per line.
 */
#[AsDashboardWidget(
    id: 'document_follow_up',
    label: 'dashboard.widget.document_follow_up',
    icon: 'tabler:eye',
    zone: WidgetZone::RightColumn,
    // Above "Recent activity": what the clients did matters more than what
    // was created.
    priority: 55,
)]
final readonly class DocumentFollowUpWidget implements WidgetInterface
{
    private const int ROWS_SHOWN = 8;

    /**
     * @var array<string, class-string>
     */
    private const array DOCUMENT_CLASSES = [
        'quote' => Quote::class,
        'invoice' => Invoice::class,
        'credit_note' => CreditNote::class,
    ];

    public function __construct(
        private DocumentActivityRepository $activities,
        private ManagerRegistry $doctrine,
        private ClockInterface $clock,
    ) {
    }

    public function supports(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $entries = array_values(array_filter(
            $this->activities->recentClientSide(self::ROWS_SHOWN * 2),
            static fn (DocumentActivity $entry): bool => ! $entry->isLikelyAutomated(),
        ));
        $entries = array_slice($entries, 0, self::ROWS_SHOWN);

        $documents = $this->documents($entries);
        $lines = [];

        foreach ($entries as $entry) {
            $document = $documents[$entry->getKind()->value][(string) $entry->getRecordId()] ?? null;

            // A document deleted since (a draft) leaves its history behind.
            if (null === $document) {
                continue;
            }

            $lines[] = ['entry' => $entry, 'document' => $document, 'kind' => $entry->getKind()];
        }

        return [
            'lines' => $lines,
            'failedRecently' => $this->activities->countFailedSince($this->clock->now()->modify('-30 days')),
        ];
    }

    public function getTemplate(): string
    {
        return '@AugiasDashboard/Widget/document_follow_up.html.twig';
    }

    /**
     * @param list<DocumentActivity> $entries
     *
     * @return array<string, array<string, object>>
     */
    private function documents(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $ids[$entry->getKind()->value][] = $entry->getRecordId();
        }

        $documents = [];

        foreach ($ids as $kind => $kindIds) {
            $class = self::DOCUMENT_CLASSES[$kind] ?? null;

            if (null === $class) {
                continue;
            }

            foreach ($this->doctrine->getRepository($class)->findBy(['id' => $kindIds]) as $document) {
                $documents[$kind][(string) $document->getId()] = $document;
            }
        }

        return $documents;
    }
}
