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

namespace Augias\BillBundle\DataGrid;

use Augias\BillBundle\Entity\Bill;
use Augias\BillBundle\Enum\BillStatus;
use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\Action;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Action\ViewAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\RelativeDateColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Translation\TranslatableMessage;
use function array_column;
use function array_key_exists;
use function array_map;

/**
 * @see \Augias\BillBundle\Tests\DataGrid\BillGridTest
 */
#[AsDataGrid(name: 'bill_grid', title: 'Bills')]
final class BillGrid extends Grid
{
    public function entityFQCN(): string
    {
        return Bill::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('supplier')
                ->label('bill.grid.supplier')
                ->searchable(false)
                ->linkToRoute('_suppliers_view', ['id' => 'supplier.id']),
            StringColumn::new('billNumber')
                ->label('bill.grid.bill_number'),
            StringColumn::new('status')
                ->label('bill.grid.status')
                ->twigFunction('bill_label')
                ->filter(ChoiceFilter::new('status', array_column(array_map(static fn (BillStatus $s) => [$s->value, $s->getLabel()], BillStatus::cases()), 1, 0))->multiple()),
            MoneyColumn::new('total')
                ->label('bill.grid.total')
                ->searchable(false)
                ->sortableField('totalAmount'),
            MoneyColumn::new('balance')
                ->label('bill.grid.balance')
                ->searchable(false)
                ->sortableField('totalAmount'),
            RelativeDateColumn::new('dueDate')
                ->label('bill.grid.due_date')
                ->width('long')
                ->filter(new DateRangeFilter('dueDate'))
                ->hiddenByDefault(),
            StringColumn::new('category')
                ->label('bill.grid.category')
                ->searchable(false)
                ->hiddenByDefault(),
            RelativeDateColumn::new('issueDate')
                ->label('bill.grid.issue_date')
                ->width('long')
                ->filter(new DateRangeFilter('issueDate'))
                ->hiddenByDefault(),
        ];
    }

    /**
     * @return list<ViewAction|EditAction|Action>
     */
    #[Override]
    public function actions(): array
    {
        return [
            ViewAction::new('_bills_view', ['id' => 'id']),
            EditAction::new('_bills_edit', ['id' => 'id']),
            Action::new('_bills_record_payment', ['id' => 'id'])
                ->icon('cash')
                ->label('Record Payment')
                ->inMenu(),
        ];
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $query = parent::query($entityManager, $query);

        if (array_key_exists('supplier_id', $this->context) && null !== $this->context['supplier_id']) {
            $query
                ->getQueryBuilder()
                ->andWhere(ORMSource::ALIAS . '.supplier = :supplier_id')
                ->setParameter('supplier_id', $this->context['supplier_id'], UlidType::NAME);
        }

        $query->getQueryBuilder()->orderBy(ORMSource::ALIAS . '.issueDate', 'DESC');

        return $query;
    }

    public function getCreateRoute(): ?string
    {
        return '_bills_add';
    }

    #[Override]
    public function getCreateLabel(): ?TranslatableMessage
    {
        return new TranslatableMessage('bill.grid.create');
    }

    #[Override]
    public function getEmptyTitle(): TranslatableMessage
    {
        return new TranslatableMessage('datagrid.empty.bill.title');
    }

    #[Override]
    public function getEmptyDescription(): TranslatableMessage
    {
        return new TranslatableMessage('datagrid.empty.bill.description');
    }
}
