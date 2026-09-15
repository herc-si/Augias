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

namespace Augias\TaxBundle\DataGrid;

use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Batch\BatchAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Repository\TaxRepository;
use Override;

#[AsDataGrid(name: 'tax_grid', title: 'Tax Rates')]
final class TaxGrid extends Grid
{
    public function entityFQCN(): string
    {
        return Tax::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('name')
                ->label('tax.grid.name'),
            StringColumn::new('rate')
                ->label('tax.grid.rate')
                ->formatValue(static fn (string | float $value) => (string) $value . '%'),
            StringColumn::new('type')
                ->label('tax.grid.type'),
            StringColumn::new('category')
                ->label('tax.grid.category')
                ->formatValue(static fn (mixed $value) => $value instanceof TaxCategory ? $value->getLabel() : (string) $value)
                ->filter(ChoiceFilter::new('category', array_column(array_map(static fn (TaxCategory $c) => [$c->value, $c->getLabel()], TaxCategory::cases()), 1, 0))->multiple()),
            DateTimeColumn::new('created')
                ->label('tax.grid.created')
                ->width('long'),
        ];
    }

    #[Override]
    public function batchActions(): iterable
    {
        return [
            BatchAction::new('Delete')
                ->icon('trash')
                ->color('danger')
                ->confirmMessage(<<<MSG
Are you sure you want to delete the selected tax rates?\n
Note, deleting a tax rate will remove it from all invoices and quotes that are using it and affect the totals.
MSG)
                ->action(static function (TaxRepository $repository, array $selectedItems): void {
                    $repository->deleteTaxRates($selectedItems);
                }),
        ];
    }

    /**
     * @return EditAction[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            EditAction::new('_tax_rates_edit', ['id' => 'id']),
        ];
    }

    public function getCreateRoute(): ?string
    {
        return '_tax_rates_add';
    }
}
