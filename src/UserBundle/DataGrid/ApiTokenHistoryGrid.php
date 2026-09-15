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

namespace Augias\UserBundle\DataGrid;

use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Grid;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Column\DateTimeColumn;
use Augias\DataGridBundle\GridBuilder\Column\StringColumn;
use Augias\DataGridBundle\GridBuilder\Filter\ChoiceFilter;
use Augias\DataGridBundle\GridBuilder\Filter\DateRangeFilter;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\Source\ORMSource;
use Augias\UserBundle\Entity\ApiTokenHistory;
use Augias\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @see \Augias\UserBundle\Tests\DataGrid\ApiTokenHistoryGridTest
 */
#[AsDataGrid(name: 'api_token_history_grid', title: 'Request History')]
final class ApiTokenHistoryGrid extends Grid
{
    public function __construct(
        private readonly Security $security
    ) {
    }

    public function entityFQCN(): string
    {
        return ApiTokenHistory::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            DateTimeColumn::new('created')
                ->label('user.api_token.history.grid.date')
                ->width('medium', 'short')
                ->sortable(true)
                ->filter(new DateRangeFilter('created')),
            StringColumn::new('method')
                ->label('user.api_token.history.grid.method')
                ->sortable(false)
                ->filter(new ChoiceFilter('method', [
                    'GET' => 'GET',
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH',
                    'DELETE' => 'DELETE',
                ])),
            StringColumn::new('resource')
                ->label('user.api_token.history.grid.endpoint')
                ->sortable(false)
                ->formatValue(static fn (?string $value) => $value ? (strlen($value) > 40 ? substr($value, 0, 40) . '...' : $value) : '-'),
            StringColumn::new('statusCode')
                ->label('user.api_token.history.grid.status')
                ->sortable(false)
                ->formatValue(static fn ($value, ApiTokenHistory $history) => $history->getStatusCode() ?? '-')
                ->filter(new ChoiceFilter('statusCode', [
                    '2xx' => '2xx Success',
                    '3xx' => '3xx Redirect',
                    '4xx' => '4xx Client Error',
                    '5xx' => '5xx Server Error',
                ])),
            StringColumn::new('ip')
                ->label('user.api_token.history.grid.ip')
                ->sortable(false),
            StringColumn::new('userAgent')
                ->label('user.api_token.history.grid.user_agent')
                ->sortable(false)
                ->formatValue(static fn (?string $value) => $value ? (strlen($value) > 30 ? substr($value, 0, 30) . '...' : $value) : '-'),
        ];
    }

    #[Override]
    public function actions(): array
    {
        return [];
    }

    #[Override]
    public function query(EntityManagerInterface $entityManager, Query $query): Query
    {
        $user = $this->security->getUser();

        assert($user instanceof User);

        $queryBuilder = $query->getQueryBuilder();

        // Only ever expose history for tokens that belong to the current user
        $queryBuilder
            ->join(ORMSource::ALIAS . '.token', 'apiToken')
            ->andWhere('apiToken.user = :user')
            ->setParameter('user', $user->getId(), UlidType::NAME);

        // Filter by token ID if provided in context
        if (isset($this->context['token_id'])) {
            $queryBuilder
                ->andWhere('IDENTITY(' . ORMSource::ALIAS . '.token) = :token')
                ->setParameter('token', $this->context['token_id'], UlidType::NAME);
        }

        $queryBuilder
            ->orderBy(ORMSource::ALIAS . '.created', 'DESC')
            ->setMaxResults(100);

        return $query;
    }
}
