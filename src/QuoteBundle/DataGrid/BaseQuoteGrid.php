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

namespace Augias\QuoteBundle\DataGrid;

use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\Action;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Action\ViewAction;
use Augias\DataGridBundle\GridBuilder\Batch\BatchAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Order\NumberedOrder;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Augias\MoneyBundle\Calculator;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Repository\QuoteRepository;
use Brick\Math\BigNumber;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Override;

abstract class BaseQuoteGrid extends Grid
{
    public function __construct(
        protected readonly Calculator $calculator,
    ) {
    }

    public function entityFQCN(): string
    {
        return Quote::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('quoteId')
                ->label('quote.grid.quote_number')
                ->cellClass('col-id')
                ->naturalSort(),
            StringColumn::new('client')
                ->label('quote.grid.client')
                ->searchable(false)
                ->linkToRoute('_clients_view', ['id' => 'client.id']),
            MoneyColumn::new('total')
                ->label('quote.grid.total')
                ->formatValue(fn (BigNumber $value, Quote $quote) => new Money((string) $value, $quote->getClient()?->getCurrency())),
            StringColumn::new('status')
                ->label('quote.grid.status')
                ->twigFunction('quote_label')
                ->filter(ChoiceFilter::new('status', array_column(array_map(static fn (QuoteStatus $s) => [$s->value, $s->name], QuoteStatus::cases()), 1, 0))->multiple()),
            MoneyColumn::new('tax')
                ->label('quote.grid.tax')
                ->formatValue(fn (BigNumber $value, Quote $quote) => new Money((string) $value, $quote->getClient()?->getCurrency())),
            MoneyColumn::new('discount.value')
                ->label('quote.grid.discount')
                ->searchable(false)
                ->formatValue(function (float | BigNumber $value, Quote $quote): Money {
                    $discountAmount = $this->calculator->calculateDiscount($quote);

                    return new Money((string) $discountAmount, $quote->getClient()?->getCurrency());
                }),
            DateTimeColumn::new('created')
                ->label('quote.grid.created')
                ->format('d F Y')
                ->filter(new DateRangeFilter('created'))
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            ViewAction::new('_quotes_view', ['id' => 'id']),
            EditAction::new('_quotes_edit', ['id' => 'id']),
        ];
    }

    /**
     * A quote list had no order of its own, so it came back in whatever order
     * the database chose — which is stable enough to look deliberate and
     * arbitrary enough to be wrong. Newest first, most recent number first
     * within a day, as invoices and credit notes already do. Quotes carry no
     * date of their own, so it is the one they were created on.
     */
    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $queryBuilder = $query->getQueryBuilder();

        $queryBuilder->orderBy(ORMSource::ALIAS . '.created', 'DESC');

        NumberedOrder::apply($queryBuilder, ORMSource::ALIAS . '.quoteId', 'DESC');

        return $query;
    }

    #[Override]
    public function batchActions(): iterable
    {
        yield BatchAction::new('Delete')
            ->icon('trash')
            ->color('danger')
            ->action(static function (QuoteRepository $repository, array $selectedItems): void {
                $repository->deleteQuotes($selectedItems);
            });
    }
}
