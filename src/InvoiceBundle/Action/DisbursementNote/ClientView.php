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

namespace Augias\InvoiceBundle\Action\DisbursementNote;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\InvoiceBundle\Document\DisbursementNoteRenderer;
use Augias\InvoiceBundle\Entity\Invoice;
use Doctrine\Persistence\ManagerRegistry;
use Mpdf\MpdfException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * The disbursement note, from the client's copy of the invoice — reached
 * through the invoice's uuid, the credential the copy itself rests on.
 */
final readonly class ClientView
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CompanySelector $companySelector,
        private EmailVerificationGateInterface $emailVerificationGate,
        private DisbursementNoteRenderer $renderer,
    ) {
    }

    /**
     * @throws MpdfException|LoaderError|RuntimeError|SyntaxError
     */
    public function __invoke(string $uuid): Response
    {
        $invoice = $this->doctrine->getRepository(Invoice::class)->findOneBy(['uuid' => $uuid]);

        if (! $invoice instanceof Invoice || $this->emailVerificationGate->isCompanyGated($invoice->getCompany())) {
            throw new NotFoundHttpException('No such document.');
        }

        $this->companySelector->switchCompany($invoice->getCompany()->getId());

        return $this->renderer->response($invoice);
    }
}
