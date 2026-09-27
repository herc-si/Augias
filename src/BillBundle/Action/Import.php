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

namespace Augias\BillBundle\Action;

use Augias\BillBundle\Import\BillImporter;
use Augias\BillBundle\Import\UnreadableInvoice;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use function file_get_contents;

/**
 * Creates a bill from a supplier's Factur-X file and opens it for checking.
 * A file without an embedded invoice sends the user back to type it in.
 */
final readonly class Import
{
    /** A Factur-X invoice is a few hundred kilobytes; this leaves room for scans. */
    private const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private BillImporter $importer,
        private CompanySelector $companySelector,
        private CompanyRepository $companies,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Request $request, Session $session): RedirectResponse
    {
        $back = new RedirectResponse($this->router->generate('_bills_add'));
        $file = $request->files->get('invoice');

        if (! $this->csrf->isTokenValid(new CsrfToken('bill_import', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'bill.import.invalid_token');

            return $back;
        }

        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $session->getFlashBag()->add('danger', 'bill.import.no_file');

            return $back;
        }

        $company = $this->companies->find($this->companySelector->getCompany());

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        try {
            $bill = $this->importer->import($company, (string) file_get_contents($file->getPathname()));
        } catch (UnreadableInvoice $unreadable) {
            $session->getFlashBag()->add('warning', 'bill.import.error.' . $unreadable->reason);

            return $back;
        }

        $session->getFlashBag()->add('success', 'bill.import.success');

        return new RedirectResponse($this->router->generate('_bills_edit', ['id' => $bill->getId()]));
    }
}
