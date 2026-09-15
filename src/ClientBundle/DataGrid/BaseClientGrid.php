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

namespace Augias\ClientBundle\DataGrid;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Action\Action;
use Augias\DataGridBundle\GridBuilder\Action\EditAction;
use Augias\DataGridBundle\GridBuilder\Action\ViewAction;
use Augias\DataGridBundle\GridBuilder\Batch\BatchAction;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\CurrencyColumn;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Column\MoneyColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Column\UrlColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Translation\TranslatableMessage;

abstract class BaseClientGrid extends Grid
{
    public function __construct(
        private readonly string $locale
    ) {
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('name')
                ->label('client.grid.name'),
            StringColumn::new('role')
                ->label(new TranslatableMessage('client.grid.role'))
                ->searchable(false)
                ->sortable(false)
                ->formatValue(static function (mixed $value, Client $client): TranslatableMessage {
                    return match (true) {
                        $client->isClient() && $client->isSupplier() => new TranslatableMessage('client.grid.role_both'),
                        $client->isSupplier() => new TranslatableMessage('client.grid.role_supplier'),
                        default => new TranslatableMessage('client.grid.role_client'),
                    };
                }),
            UrlColumn::new('website')
                ->label('client.grid.website'),
            CurrencyColumn::new('currencyCode')
                ->label(new TranslatableMessage('client.grid.currency'))
                ->filter(new ChoiceFilter('currencyCode', Currencies::getNames($this->locale))),
            MoneyColumn::new('total')
                ->label(new TranslatableMessage('client.grid.total_balance'))
                ->sortable(false)
                ->searchable(false)
                ->formatValue(static function ($value, Client $client) {
                    $total = BigInteger::zero();

                    foreach ($client->getInvoices() as $invoice) {
                        if (
                            in_array($invoice->getStatus(), [InvoiceStatus::Paid, InvoiceStatus::Pending, InvoiceStatus::Overdue], true)
                        ) {
                            $total = $total->plus($invoice->getTotal());
                        }
                    }

                    return $total;
                }),
            MoneyColumn::new('outstanding')
                ->label(new TranslatableMessage('client.grid.outstanding_balance'))
                ->sortable(false)
                ->searchable(false)
                ->formatValue(static function ($value, Client $client) {
                    $totalOutstanding = BigInteger::zero();

                    foreach ($client->getOutstandingInvoices() as $invoice) {
                        $totalOutstanding = $totalOutstanding->plus($invoice->getBalance());
                    }

                    return $totalOutstanding;
                }),
            DateTimeColumn::new('created')
                ->label('client.grid.created')
                ->width('long')
                ->filter(new DateRangeFilter('created')),
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            ViewAction::new('_clients_view', ['id' => 'id']),
            EditAction::new('_clients_edit', ['id' => 'id']),
        ];
    }

    public function entityFQCN(): string
    {
        return Client::class;
    }

    #[Override]
    public function batchActions(): iterable
    {
        yield BatchAction::new('Delete')
            ->icon('trash')
            ->color('danger')
            ->action(static function (ClientRepository $repository, array $selectedItems): void {
                $repository->deleteClients($selectedItems);
            });
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $query->getQueryBuilder()
            ->orderBy(ORMSource::ALIAS . '.created', 'ASC');

        return $query;
    }
}
