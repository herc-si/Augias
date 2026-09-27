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

use Augias\AccountingBundle\Bank\Reconciler;
use Augias\AccountingBundle\Bank\ReconciliationRefused;
use Augias\AccountingBundle\Entity\BankTransaction;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ties a bank line to what it is the trace of — or sets it aside, or undoes
 * either: `action` says which.
 */
final readonly class Reconcile
{
    public function __construct(
        private Reconciler $reconciler,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(BankTransaction $line, string $action, Request $request, Session $session): RedirectResponse
    {
        $back = new RedirectResponse($this->router->generate('_accounting_bank', [
            'account' => $line->getBankAccount()->getId(),
            'status' => $request->request->get('status'),
        ]));

        if (! $this->csrf->isTokenValid(new CsrfToken('bank', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

            return $back;
        }

        try {
            match ($action) {
                'match' => $this->reconciler->match($line, (string) $request->request->get('kind'), (string) $request->request->get('target')),
                'ignore' => $this->reconciler->ignore($line),
                default => $this->reconciler->reopen($line),
            };
        } catch (ReconciliationRefused $refused) {
            $session->getFlashBag()->add('warning', $refused->trans($this->translator));

            return $back;
        }

        $session->getFlashBag()->add('success', 'accounting.bank.flash.' . $action);

        return $back;
    }
}
