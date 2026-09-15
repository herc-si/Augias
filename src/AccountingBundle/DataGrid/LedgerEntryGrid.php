<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\DataGrid;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\Action;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use function array_key_exists;
use function is_string;

/**
 * One statutory book, shown as it has to read: chronological, with the
 * columns the register is required to carry — date, nature of the operation,
 * counterparty, supporting document, amount and method of settlement.
 *
 * Both books share this grid and are told apart by the `book` context, which
 * every route that renders it sets. Without one the grid would mix receipts and
 * purchases into a single list that is neither register.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\AccountingPagesTest
 */
#[AsDataGrid(name: 'ledger_entry_grid', title: 'Ledger')]
final class LedgerEntryGrid extends Grid
{
    public function __construct(
        private readonly AccountingProfileProvider $profileProvider,
    ) {
    }

    public function entityFQCN(): string
    {
        return LedgerEntry::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        $columns = [
            StringColumn::new('sequenceNumber')
                ->label('accounting.entry.grid.number')
                ->searchable(false),
            DateTimeColumn::new('entryDate')
                ->label('accounting.entry.grid.date')
                ->width('long')
                ->filter(new DateRangeFilter('entryDate')),
            StringColumn::new('label')
                ->label('accounting.entry.grid.label'),
            StringColumn::new('counterpartyName')
                ->label('accounting.entry.grid.counterparty'),
            StringColumn::new('documentReference')
                ->label('accounting.entry.grid.document_reference'),
            MoneyColumn::new('money')
                ->label('accounting.entry.grid.amount')
                ->searchable(false)
                ->sortableField('amount'),
        ];

        // Only for a company that is in the scope of VAT: one in franchise en
        // base would read two permanently empty columns in the register it is
        // required to produce. Both books carry them — tax collected on one
        // side, tax paid to suppliers on the other.
        if (! $this->profileProvider->forCompany()->vatExempt) {
            $columns[] = MoneyColumn::new('netMoney')
                ->label('accounting.entry.grid.net')
                ->searchable(false)
                ->sortableField('netAmount');

            $columns[] = MoneyColumn::new('taxMoney')
                ->label('accounting.entry.grid.tax')
                ->searchable(false)
                ->sortableField('taxAmount');
        }

        return $columns;
    }

    /**
     * @return list<EditAction|Action>
     */
    #[Override]
    public function actions(): array
    {
        return [
            EditAction::new('_accounting_entry_edit', ['id' => 'id']),
        ];
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $query = parent::query($entityManager, $query);

        $query->getQueryBuilder()
            ->andWhere(ORMSource::ALIAS . '.book = :book')
            ->setParameter('book', $this->book()->value)
            // The order the register is read and numbered in. Created is the
            // tie-break so two entries on the same day keep the order they were
            // written, which is the order they will be sealed in.
            ->orderBy(ORMSource::ALIAS . '.entryDate', 'DESC')
            ->addOrderBy(ORMSource::ALIAS . '.created', 'DESC');

        return $query;
    }

    /**
     * Which of the two registers is being rendered. Every route that shows the
     * grid sets it; without one the grid would mix receipts and purchases into
     * a list that is neither register.
     */
    private function book(): LedgerBook
    {
        $book = array_key_exists('book', $this->context) ? $this->context['book'] : null;

        return (is_string($book) ? LedgerBook::tryFrom($book) : null) ?? LedgerBook::Revenue;
    }
}
