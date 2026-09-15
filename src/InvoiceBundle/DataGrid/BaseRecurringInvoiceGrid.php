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

use Augias\ClientBundle\Entity\Client;
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
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\RecurringInvoiceStatus;
use Augias\InvoiceBundle\Recurring\RecurringSchedule;
use Augias\InvoiceBundle\Repository\RecurringInvoiceRepository;
use Augias\MoneyBundle\Calculator;
use Brick\Math\BigNumber;
use DateTimeInterface;
use InvalidArgumentException;
use Money\Money;
use Override;

abstract class BaseRecurringInvoiceGrid extends Grid
{
    public function __construct(
        protected readonly RecurringSchedule $schedule,
        protected readonly Calculator $calculator,
    ) {
    }

    public function entityFQCN(): string
    {
        return RecurringInvoice::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('client')
                ->label('invoice.grid.client')
                ->searchable(false)
                ->linkToRoute('_clients_view', ['id' => 'client.id']),
            StringColumn::new('frequency')
                ->label('invoice.grid.frequency')
                ->formatValue(fn (RecurringInvoice $recurringInvoice): string => $this->schedule->getFrequency($recurringInvoice->getRecurringOptions())),
            DateTimeColumn::new('dateStart')
                ->label('invoice.grid.date_start')
                ->width('long')
                ->filter(new DateRangeFilter('dateStart')),
            DateTimeColumn::new('endDate')
                ->label('invoice.grid.end_date')
                ->width('long')
                ->formatValue(fn (RecurringInvoice $recurringInvoice) => $this->schedule->getEndDate($recurringInvoice->getRecurringOptions()))
                ->filter(new DateRangeFilter('endDate')),
            DateTimeColumn::new('nextRunDate')
                ->label('invoice.grid.next_run_date')
                ->formatValue(fn (RecurringInvoice $recurringInvoice): ?DateTimeInterface => $this->schedule->getNextRunDate($recurringInvoice->getRecurringOptions()))
                ->width('long'),
            StringColumn::new('status')
                ->label('invoice.grid.status')
                ->twigFunction('invoice_label')
                ->filter(ChoiceFilter::new('status', array_column(array_map(static fn (RecurringInvoiceStatus $s) => [$s->value, $s->name], RecurringInvoiceStatus::cases()), 1, 0))->multiple()),
            MoneyColumn::new('total')
                ->label('invoice.grid.total')
                ->formatValue(function (float | BigNumber $value, RecurringInvoice $invoice): Money {
                    $client = $invoice->getClient();
                    if (! $client instanceof Client) {
                        throw new InvalidArgumentException(sprintf('RecurringInvoice #%s must have a client with currency', $invoice->getId()));
                    }

                    return new Money((string) $value, $client->getCurrency());
                }),
            MoneyColumn::new('tax')
                ->label('invoice.grid.tax')
                ->formatValue(function (float | BigNumber $value, RecurringInvoice $invoice): Money {
                    $client = $invoice->getClient();
                    if (! $client instanceof Client) {
                        throw new InvalidArgumentException(sprintf('RecurringInvoice #%s must have a client with currency', $invoice->getId()));
                    }

                    return new Money((string) $value, $client->getCurrency());
                }),
            MoneyColumn::new('payableAmount')
                ->label('invoice.grid.payable')
                ->searchable(false)
                ->formatValue(function (BigNumber $value, RecurringInvoice $invoice): Money {
                    $client = $invoice->getClient();
                    if (! $client instanceof Client) {
                        throw new InvalidArgumentException(sprintf('RecurringInvoice #%s must have a client with currency', $invoice->getId()));
                    }

                    $withholding = $invoice->getWithholdingAmount();
                    $amount = $withholding->isPositive() ? $value : $invoice->getTotal();

                    return new Money((string) $amount, $client->getCurrency());
                }),
            MoneyColumn::new('discount.value')
                ->label('invoice.grid.discount')
                ->searchable(false)
                ->formatValue(function (float | BigNumber $value, RecurringInvoice $invoice): Money {
                    $discountAmount = $this->calculator->calculateDiscount($invoice);

                    return new Money((string) $discountAmount, $invoice->getClient()?->getCurrency());
                }),
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            ViewAction::new('_invoices_view_recurring', ['id' => 'id']),
            EditAction::new('_invoices_edit_recurring', ['id' => 'id']),
        ];
    }

    #[Override]
    public function batchActions(): iterable
    {
        yield BatchAction::new('Delete')
            ->icon('trash')
            ->color('danger')
            ->action(static function (RecurringInvoiceRepository $repository, array $selectedItems): void {
                $repository->deleteInvoices($selectedItems);
            });
    }

    public function getCreateRoute(): ?string
    {
        return '_invoices_create_recurring';
    }
}
