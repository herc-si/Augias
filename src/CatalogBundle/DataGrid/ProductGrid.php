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

namespace Augias\CatalogBundle\DataGrid;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\CatalogBundle\Repository\ProductRepository;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Batch\BatchAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\StatusColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Override;
use Symfony\Component\Translation\TranslatableMessage;
use function array_column;
use function array_map;

#[AsDataGrid(name: 'catalog_grid', title: 'catalog.grid.title')]
final class ProductGrid extends Grid
{
    public function entityFQCN(): string
    {
        return Product::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('reference')
                ->label('catalog.grid.reference'),
            StringColumn::new('name')
                ->label('catalog.grid.name'),
            StringColumn::new('type')
                ->label('catalog.grid.type')
                // Enums are objects with no __toString, which StringFormatter
                // would otherwise render as an object hash.
                ->formatValue(static fn (ProductType $value): TranslatableMessage => new TranslatableMessage($value->getLabel()))
                ->filter(ChoiceFilter::new('type', array_column(array_map(static fn (ProductType $t) => [$t->value, $t->getLabel()], ProductType::cases()), 1, 0))->multiple()),
            StringColumn::new('unit')
                ->label('catalog.grid.unit')
                ->formatValue(static fn (QuantityUnit $value): TranslatableMessage => new TranslatableMessage($value->getLabel())),
            MoneyColumn::new('salePrice')
                ->label('catalog.grid.sale_price')
                ->searchable(false),
            MoneyColumn::new('purchasePrice')
                ->label('catalog.grid.purchase_price')
                ->searchable(false)
                ->hiddenByDefault(),
            StringColumn::new('category')
                ->label('catalog.grid.category')
                ->searchable(false),
            StringColumn::new('tax')
                ->label('catalog.grid.tax')
                ->searchable(false),
            StatusColumn::new('active')
                ->label('catalog.grid.active'),
        ];
    }

    /**
     * @return EditAction[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            EditAction::new('_catalog_edit', ['id' => 'id']),
        ];
    }

    #[Override]
    public function batchActions(): iterable
    {
        yield BatchAction::new('Delete')
            ->icon('trash')
            ->color('danger')
            ->action(static function (ProductRepository $repository, array $selectedItems): void {
                $repository->deleteProducts($selectedItems);
            });
    }
}
