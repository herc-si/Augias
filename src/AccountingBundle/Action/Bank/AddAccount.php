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
use Augias\AccountingBundle\Service\CurrentCompany;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use function count;
use function trim;

/**
 * Adds a bank account to import statements into.
 */
final readonly class AddAccount
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrentCompany $currentCompany,
        private ValidatorInterface $validator,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request, Session $session): RedirectResponse
    {
        if (! $this->csrf->isTokenValid(new CsrfToken('bank', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

            return new RedirectResponse($this->router->generate('_accounting_bank'));
        }

        $account = new BankAccount()
            ->setName(trim((string) $request->request->get('name')))
            ->setIban((string) $request->request->get('iban'))
            ->setCurrencyCode((string) $request->request->get('currency', 'EUR'));
        $account->setCompany($this->currentCompany->require());

        $violations = $this->validator->validate($account);

        if (count($violations) > 0) {
            $session->getFlashBag()->add('danger', (string) $violations[0]->getMessage());

            return new RedirectResponse($this->router->generate('_accounting_bank'));
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $session->getFlashBag()->add('success', 'accounting.bank.flash.account_added');

        return new RedirectResponse($this->router->generate('_accounting_bank', ['account' => $account->getId()]));
    }
}
