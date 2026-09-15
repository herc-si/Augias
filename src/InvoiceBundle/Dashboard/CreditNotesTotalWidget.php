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

namespace Augias\InvoiceBundle\Dashboard;

use Augias\DashboardBundle\Attribute\AsDashboardWidget;
use Augias\DashboardBundle\Enum\WidgetWidth;
use Augias\DashboardBundle\Enum\WidgetZone;
use Augias\DashboardBundle\Widgets\DefaultCurrency;
use Augias\DashboardBundle\Widgets\WidgetInterface;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use function array_filter;
use function array_map;
use function assert;

/**
 * How much the company has credited, alongside what it earned.
 *
 * The top row answers "what came in" — revenue, this month, outstanding, overdue
 * — and credit notes are the one movement that works against all four while
 * appearing in none of them. Neither of the two ways a credit note is used up
 * shows there, and for good reasons that are nonetheless invisible from the
 * dashboard: an offset leaves no trace because the client simply pays less, and
 * a refund only ever reduces a total that is already counted net. The figure
 * existed nowhere.
 *
 * Distinct from the {@see CreditNotesWidget} card below it, which answers a
 * different question — what is *still* owed to clients, and on which documents.
 * This one is the lifetime figure, and it does not shrink when a client uses
 * their credit.
 *
 * Never summed across currencies, for the reason the card is not: a credit note
 * is settled in the currency it was raised in.
 *
 * @see \Augias\InvoiceBundle\Tests\Dashboard\CreditNotesTotalWidgetTest
 */
#[AsDashboardWidget(
    id: 'credit_notes_total',
    label: 'dashboard.widget.credit_notes_total',
    icon: 'tabler:receipt-refund',
    zone: WidgetZone::Top,
    // Last of the money tiles: it qualifies the four above it rather than
    // competing with them.
    priority: 200,
    width: WidgetWidth::Quarter,
)]
final readonly class CreditNotesTotalWidget implements WidgetInterface
{
    private ObjectManager $manager;

    public function __construct(
        ManagerRegistry $registry,
        private DefaultCurrency $defaultCurrency,
    ) {
        $this->manager = $registry->getManager();
    }

    /**
     * Hidden until a credit note has actually been handed to a client.
     *
     * Drafts deliberately do not count, unlike on the card below: a draft owes
     * the client nothing yet, so counting it would put a tile in the top row
     * reading zero — the one number a stat tile should never be born showing.
     */
    public function supports(): bool
    {
        $creditNotes = $this->creditNotes();

        return $creditNotes->countByStatus(CreditNoteStatus::Issued) > 0
            || $creditNotes->countByStatus(CreditNoteStatus::Settled) > 0;
    }

    /**
     * @return array<string, mixed>
     * @throws MathException
     */
    public function getData(): array
    {
        $totals = $this->creditNotes()->getIssuedTotalsByCurrency();

        return [
            'issued' => array_map(static fn (array $total): BigInteger => $total['issued'], $totals),
            // Only currencies where money actually went back. A zero refund
            // line under the headline reads as a figure worth knowing, and it
            // is not: it is the ordinary case.
            'refunded' => array_filter(
                array_map(static fn (array $total): BigInteger => $total['refunded'], $totals),
                static fn (BigInteger $amount): bool => $amount->isPositive(),
            ),
            'defaultCurrency' => $this->defaultCurrency->code(),
        ];
    }

    public function getTemplate(): string
    {
        return '@AugiasInvoice/Widget/stat_credit_notes.html.twig';
    }

    private function creditNotes(): CreditNoteRepository
    {
        $repository = $this->manager->getRepository(CreditNote::class);
        assert($repository instanceof CreditNoteRepository);

        return $repository;
    }
}
