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

namespace Augias\InvoiceBundle\DataGrid;

use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\Action;
use Augias\DataGridBundle\GridBuilder\Action\ViewAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\RelativeDateColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Brick\Math\BigNumber;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Override;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Translation\TranslatableMessage;
use function array_column;
use function array_key_exists;
use function array_map;

/**
 * No edit action in the row: a credit note stops being editable the moment it
 * is issued, and offering a pencil that works on one row in ten is worse than
 * offering none. Editing a draft is reached from the document itself.
 */
#[AsDataGrid(name: 'credit_note_grid', title: 'Credit Notes')]
final class CreditNoteGrid extends Grid
{
    public function entityFQCN(): string
    {
        return CreditNote::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('creditNoteId')
                ->label('credit_note.grid.number'),
            StringColumn::new('client')
                ->label('credit_note.grid.client')
                ->searchable(false)
                ->linkToRoute('_clients_view', ['id' => 'client.id']),
            // Not linked: the relation is nullable, and the grid builds a route
            // from the property path whether or not there is anything at the end
            // of it. The number is enough to recognise the invoice; the link to
            // it lives on the credit note's own page.
            StringColumn::new('creditedInvoice')
                ->label('credit_note.grid.credited_invoice')
                ->searchable(false),
            StringColumn::new('status')
                ->label('credit_note.grid.status')
                ->twigFunction('credit_note_label')
                ->filter(ChoiceFilter::new('status', array_column(array_map(static fn (CreditNoteStatus $s): array => [$s->value, $s->getLabel()], CreditNoteStatus::cases()), 1, 0))->multiple()),
            MoneyColumn::new('total')
                ->label('credit_note.grid.total')
                ->formatValue(static fn (BigNumber $value, CreditNote $creditNote): Money => new Money((string) $value, $creditNote->getClient()->getCurrency())),
            RelativeDateColumn::new('creditNoteDate')
                ->label('credit_note.grid.date')
                ->format('d F Y')
                ->filter(new DateRangeFilter('creditNoteDate')),

            StringColumn::new('reason')
                ->label('credit_note.grid.reason')
                ->searchable(false)
                ->filter(ChoiceFilter::new('reason', array_column(array_map(static fn (CreditReason $r): array => [$r->value, $r->getLabel()], CreditReason::cases()), 1, 0))->multiple())
                ->hiddenByDefault(),
            MoneyColumn::new('tax')
                ->label('credit_note.grid.tax')
                ->formatValue(static fn (BigNumber $value, CreditNote $creditNote): Money => new Money((string) $value, $creditNote->getClient()->getCurrency()))
                ->hiddenByDefault(),
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            ViewAction::new('_credit_notes_view', ['id' => 'id']),
        ];
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $builder = $query->getQueryBuilder();

        $builder
            ->select(ORMSource::ALIAS, 'client')
            ->innerJoin(ORMSource::ALIAS . '.client', 'client')
            ->leftJoin(ORMSource::ALIAS . '.creditedInvoice', 'creditedInvoice')
            ->addSelect('creditedInvoice')
            ->orderBy(ORMSource::ALIAS . '.creditNoteDate', 'DESC');

        if (array_key_exists('client_id', $this->context)) {
            $builder
                ->andWhere(ORMSource::ALIAS . '.client = :client_id')
                ->setParameter('client_id', $this->context['client_id'], UlidType::NAME);
        }

        return $query;
    }

    public function getCreateRoute(): string
    {
        return '_credit_notes_create';
    }

    #[Override]
    public function getCreateLabel(): TranslatableMessage
    {
        return new TranslatableMessage('Create Credit Note');
    }
}
