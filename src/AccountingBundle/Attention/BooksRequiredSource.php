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

namespace Augias\AccountingBundle\Attention;

use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Augias\DashboardBundle\Attention\AttentionSourceInterface;

/**
 * A VAT-registered company with private customers and no books kept in the
 * application: the payments of those customers cannot be recorded — see
 * {@see CashRegisterGateInterface} — and the card says why before the user
 * runs into the refusal.
 *
 * @see \Augias\AccountingBundle\Tests\Compliance\CashRegisterGateTest
 */
final readonly class BooksRequiredSource implements AttentionSourceInterface
{
    public function __construct(
        private CashRegisterGateInterface $gate,
        private ClientRepository $clients,
    ) {
    }

    public function supports(): bool
    {
        return $this->gate->requiresBooks();
    }

    public function hasItems(): bool
    {
        return $this->clients->countPrivateCustomers() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return ['privateCustomers' => $this->clients->countPrivateCustomers()];
    }

    public function getTemplate(): string
    {
        return '@AugiasAccounting/Widget/_attention_books_required.html.twig';
    }
}
