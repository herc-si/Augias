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

namespace Augias\CoreBundle\Action;

use Augias\CoreBundle\Company\CompanyClosure;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The owner changes their mind before the closure date: the company is
 * back as it was, nothing having been deleted.
 */
final class CancelCompanyClosure extends AbstractController
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyClosure $closure,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('cancel_company_closure', (string) $request->request->get('_token'))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $company = $this->companyRepository->find($this->companySelector->getCompany());

        if (! $company instanceof Company) {
            throw $this->createNotFoundException();
        }

        $this->closure->cancel($company);
        $this->addFlash('success', 'flash.company_closure_cancelled');

        return $this->redirectToRoute('_dashboard');
    }
}
