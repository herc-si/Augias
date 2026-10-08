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

use Augias\AccountingBundle\Entity\BankAccount;
use Augias\AccountingBundle\Repository\BankAccountRepository;
use Augias\CoreBundle\Company\BankDetails;
use Augias\CoreBundle\Company\CompanyBankDetails;
use Augias\SettingsBundle\SystemConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use function implode;
use function str_split;

/**
 * Puts a bank account's IBAN on the company's invoices: the bank details
 * under Settings › Company take its IBAN, and its name as the bank's. The BIC
 * is the account's bank's own, which the bank page does not know: it is kept
 * when the IBAN stays the same, cleared otherwise, and the company is sent to
 * its settings to check it.
 */
final readonly class UseOnInvoices
{
    public function __construct(
        private BankAccountRepository $accounts,
        private CompanyBankDetails $bankDetails,
        private SystemConfig $systemConfig,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request, Session $session, string $id): RedirectResponse
    {
        if (! $this->csrf->isTokenValid(new CsrfToken('bank', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

            return new RedirectResponse($this->router->generate('_accounting_bank'));
        }

        $account = $this->accounts->find($id);

        if (! $account instanceof BankAccount || null === $account->getIban()) {
            throw new NotFoundHttpException();
        }

        $iban = BankDetails::compact($account->getIban());

        if ($this->bankDetails->get()?->iban !== $iban) {
            $this->systemConfig->set(CompanyBankDetails::BIC, null);
        }

        $this->systemConfig->set(CompanyBankDetails::IBAN, implode(' ', str_split($iban, 4)));
        $this->systemConfig->set(CompanyBankDetails::BANK_NAME, $account->getName());
        $session->getFlashBag()->add('success', 'accounting.bank.flash.on_invoices');

        return new RedirectResponse($this->router->generate('_settings', ['section' => 'system']) . '#bank-details-title');
    }
}
