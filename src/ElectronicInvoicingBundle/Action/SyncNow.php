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

namespace Augias\ElectronicInvoicingBundle\Action;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use function is_string;
use function parse_url;
use function str_starts_with;

/**
 * "Synchroniser maintenant": the invoices received, and where the sent ones
 * stand, without waiting for the hourly tasks — for the current company.
 */
final class SyncNow extends AbstractController
{
    public const string CSRF = 'einvoicing_sync';

    public function __construct(
        private readonly ElectronicInvoicingSync $sync,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $back = $this->back($request);

        if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'einvoicing.sync.invalid_token');

            return $this->redirect($back);
        }

        $companyId = $this->companySelector->getCompany();
        $company = null === $companyId ? null : $this->companyRepository->find($companyId);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        $result = $this->sync->syncNow($company);

        if (null === $result) {
            $this->addFlash('warning', 'einvoicing.sync.too_soon');

            return $this->redirect($back);
        }

        $this->addFlash($result['failed'] > 0 ? 'warning' : 'success', $this->translator->trans('einvoicing.sync.done', [
            '%received%' => $result['received'],
            '%refreshed%' => $result['refreshed'],
            '%failed%' => $result['failed'],
        ]));

        return $this->redirect($back);
    }

    /**
     * Back to the page the button was on — within the application only.
     */
    private function back(Request $request): string
    {
        $path = $request->request->get('_back');

        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && null === parse_url($path, PHP_URL_HOST)) {
            return $path;
        }

        return $this->generateUrl('_einvoicing_incoming');
    }
}
