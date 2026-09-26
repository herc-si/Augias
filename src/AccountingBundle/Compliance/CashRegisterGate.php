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

namespace Augias\AccountingBundle\Compliance;

use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\CompanyBooks;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Augias\CoreBundle\Entity\Company;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use function in_array;

/**
 * The payments of a company are booked the moment they are recorded exactly
 * when its regime keeps the revenue book — the condition LedgerFeeder writes
 * them under. Without it, recording a private customer's payment would be
 * keeping a cash register the law wants certified.
 *
 * @see \Augias\AccountingBundle\Tests\Compliance\CashRegisterGateTest
 */
#[AsAlias(CashRegisterGateInterface::class)]
final readonly class CashRegisterGate implements CashRegisterGateInterface
{
    public function __construct(
        private AccountingProfileProvider $profiles,
        private CompanyBooks $books,
    ) {
    }

    public function requiresBooks(?Company $company = null): bool
    {
        if ($this->profiles->isVatExempt($company)) {
            return false;
        }

        return ! in_array(LedgerBook::Revenue, $this->books->statutory($this->profiles->forCompany($company)), true);
    }

    public function refusesPaymentFrom(Company $company, ?Client $client): bool
    {
        return $client instanceof Client && ! $client->isCompany() && $this->requiresBooks($company);
    }
}
