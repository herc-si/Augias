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

use Augias\ClientBundle\Repository\ClientRepository;
use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\GridBuilder\Batch\BatchAction;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Component\Translation\TranslatableMessage;

#[AsDataGrid(name: 'client_grid', title: 'Clients')]
final class ClientGrid extends BaseClientGrid
{
    #[Override]
    public function batchActions(): iterable
    {
        yield from parent::batchActions();

        yield BatchAction::new('Archive')
            ->icon('trash')
            ->color('warning')
            ->action(static function (ClientRepository $repository, array $selectedItems): void {
                $repository->archiveClients($selectedItems);
            });
    }

    /**
     * Clients only: a pure supplier is listed under Fournisseurs, see
     * SupplierBundle\DataGrid\SupplierGrid.
     */
    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $query = parent::query($entityManager, $query);

        $query->getQueryBuilder()
            ->andWhere(ORMSource::ALIAS . '.isClient = true');

        return $query;
    }

    public function getCreateRoute(): ?string
    {
        return '_clients_add';
    }

    #[Override]
    public function getCreateLabel(): ?TranslatableMessage
    {
        return new TranslatableMessage('client.grid.create');
    }

    #[Override]
    public function getEmptyTitle(): TranslatableMessage
    {
        return new TranslatableMessage('datagrid.empty.client.title');
    }

    #[Override]
    public function getEmptyDescription(): TranslatableMessage
    {
        return new TranslatableMessage('datagrid.empty.client.description');
    }
}
