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

namespace Augias\InvoiceBundle\Action\DisbursementReceipt;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Invoice;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A receipt, downloaded from the client's copy of the invoice.
 *
 * The client is not signed in; what they hold is the invoice's unguessable
 * uuid, the same credential their copy already rests on. The receipt's own id
 * is not enough on its own — it has to belong to a line of *that* invoice, or
 * anyone holding one link could walk to every other company's receipts.
 */
final readonly class ClientDownload
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CompanySelector $companySelector,
        private EmailVerificationGateInterface $emailVerificationGate,
        private Download $download,
    ) {
    }

    public function __invoke(string $uuid, string $id): Response
    {
        $invoice = $this->doctrine->getRepository(Invoice::class)->findOneBy(['uuid' => $uuid]);

        if (! $invoice instanceof Invoice || $this->emailVerificationGate->isCompanyGated($invoice->getCompany())) {
            throw new NotFoundHttpException('No such document.');
        }

        // The same step the client's copy takes: company-scoped reads only
        // see this invoice's company from here on.
        $this->companySelector->switchCompany($invoice->getCompany()->getId());

        $receipt = $this->doctrine->getRepository(DisbursementReceipt::class)->find($id);

        if (! $receipt instanceof DisbursementReceipt || $receipt->getLine()->getInvoice()?->getId()?->equals($invoice->getId()) !== true) {
            throw new NotFoundHttpException('No such document.');
        }

        return ($this->download)($receipt);
    }
}
