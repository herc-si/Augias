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

use Augias\AccountingBundle\Bank\StatementImporter;
use Augias\AccountingBundle\Bank\UnreadableStatement;
use Augias\AccountingBundle\Entity\BankAccount;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function file_get_contents;

/**
 * Imports a statement file — CAMT.053, OFX or CSV — into an account.
 */
final readonly class Import
{
    /** Far above a year of statements for a small business, far below trouble. */
    private const int MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private StatementImporter $importer,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(BankAccount $account, Request $request, Session $session): RedirectResponse
    {
        $back = new RedirectResponse($this->router->generate('_accounting_bank', ['account' => $account->getId()]));
        $file = $request->files->get('statement');

        if (! $this->csrf->isTokenValid(new CsrfToken('bank', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

            return $back;
        }

        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $session->getFlashBag()->add('danger', 'accounting.bank.flash.no_file');

            return $back;
        }

        try {
            $result = $this->importer->import($account, (string) file_get_contents($file->getPathname()));
        } catch (UnreadableStatement $exception) {
            $session->getFlashBag()->add('danger', $exception->trans($this->translator));

            return $back;
        }

        $session->getFlashBag()->add('success', $this->translator->trans('accounting.bank.flash.imported', [
            '%format%' => $result->format,
            '%imported%' => $result->imported,
            '%duplicates%' => $result->duplicates,
        ]));

        return $back;
    }
}
