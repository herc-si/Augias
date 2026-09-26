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

namespace Augias\AccountingBundle\Action\Bank;

use Augias\AccountingBundle\Bank\ReconciliationSuggester;
use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Enum\BankTransactionStatus;
use Augias\AccountingBundle\Repository\BankAccountRepository;
use Augias\AccountingBundle\Repository\BankTransactionRepository;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The bank page: the accounts, the statement import, and the lines still to
 * be tied to something — each with what it most likely is.
 *
 * Statements are downloaded from the bank and imported by hand, as in Odoo
 * Community: no aggregator, no approval, no cost per account.
 */
final readonly class Index
{
    public function __construct(
        private BankAccountRepository $accounts,
        private BankTransactionRepository $transactions,
        private ReconciliationSuggester $suggester,
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@AugiasAccounting/Bank/index.html.twig')]
    public function __invoke(Request $request, ?string $account = null): array
    {
        $accounts = $this->accounts->findAllOrdered();
        $current = null;

        foreach ($accounts as $candidate) {
            if (null === $account || (string) $candidate->getId() === $account) {
                $current = $candidate;

                break;
            }
        }

        if (null !== $account && ! $current instanceof BankAccount) {
            throw new NotFoundHttpException();
        }

        $status = BankTransactionStatus::tryFrom((string) $request->query->get('status', BankTransactionStatus::Unmatched->value));
        $lines = $current instanceof BankAccount ? $this->transactions->findForAccount($current, $status) : [];
        $suggestions = [];

        foreach ($lines as $line) {
            if (BankTransactionStatus::Unmatched === $line->getStatus()) {
                $suggestions[(string) $line->getId()] = $this->suggester->suggest($line);
            }
        }

        return [
            'accounts' => $accounts,
            'account' => $current,
            'status' => $status,
            'lines' => $lines,
            'suggestions' => $suggestions,
            'unmatched' => $current instanceof BankAccount ? $this->transactions->countUnmatched($current) : 0,
            'defaultCurrency' => $this->systemConfig->getCurrency()->getCode(),
        ];
    }
}
