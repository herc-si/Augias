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
use Augias\DashboardBundle\Widgets\WidgetInterface;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Brick\Math\Exception\MathException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use function assert;

/**
 * What the company owes its clients.
 *
 * The dashboard answers "where do I stand" with money coming in and, since
 * purchases, money owed to suppliers. Credit owed to a client is a third thing
 * and was nowhere: an issued credit note is a debt, and one that sits unsettled
 * for months is a debt the company has forgotten it has.
 *
 * Never summed across currencies, for the same reason the purchases card is
 * not: a credit note is settled in the currency it was raised in.
 *
 * @see \Augias\InvoiceBundle\Tests\Dashboard\CreditNotesWidgetTest
 */
#[AsDashboardWidget(
    id: 'credit_notes',
    label: 'dashboard.widget.credit_notes',
    icon: 'tabler:receipt-refund',
    zone: WidgetZone::LeftColumn,
    // Under purchases. Owing a client money is real, but rarer and less urgent
    // than the two sides of trading that sit above it.
    priority: 85,
    width: WidgetWidth::Full,
)]
final readonly class CreditNotesWidget implements WidgetInterface
{
    private const int ROWS_SHOWN = 5;

    private ObjectManager $manager;

    public function __construct(ManagerRegistry $registry)
    {
        $this->manager = $registry->getManager();
    }

    /**
     * Hidden until the company has raised one.
     *
     * Unlike purchases, an empty card here teaches nothing: a business that has
     * never issued a credit note does not need a permanent reminder that credit
     * notes exist, and supports() runs before getData(), so it costs a count
     * rather than the whole card's queries.
     */
    public function supports(): bool
    {
        return $this->creditNotes()->count([]) > 0;
    }

    /**
     * @return array<string, mixed>
     * @throws MathException
     */
    public function getData(): array
    {
        $creditNotes = $this->creditNotes();
        $outstanding = $creditNotes->getOutstanding(self::ROWS_SHOWN);

        return [
            'owed' => $creditNotes->getOutstandingByCurrency(),
            'creditNotes' => $outstanding,
            // The uncapped count, so the card can say "5 of 12" rather than
            // presenting the capped list as the whole truth.
            'issuedTotal' => $creditNotes->countByStatus(CreditNoteStatus::Issued),
            'draftTotal' => $creditNotes->countByStatus(CreditNoteStatus::Draft),
            'hasOutstanding' => [] !== $outstanding,
        ];
    }

    public function getTemplate(): string
    {
        return '@AugiasInvoice/Widget/credit_notes.html.twig';
    }

    private function creditNotes(): CreditNoteRepository
    {
        $repository = $this->manager->getRepository(CreditNote::class);
        assert($repository instanceof CreditNoteRepository);

        return $repository;
    }
}
