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

use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Entity\RecurringInvoiceLine;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use function array_keys;

/**
 * Attaches the supplier's document to a disbursement line.
 *
 * Allowed whatever state the invoice is in. The receipt is not part of what
 * was invoiced — it changes no figure and no mention — and it often arrives
 * after the invoice went out; refusing it then would leave the disbursement
 * unjustified for good.
 */
final readonly class Upload
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private DocumentStorage $storage,
        private ValidatorInterface $validator,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RouterInterface $router,
    ) {
    }

    public function __invoke(Line $line, Request $request, Session $session): Response
    {
        $invoice = self::invoiceOf($line);
        $back = new RedirectResponse($this->router->generate('_invoices_view', ['id' => $invoice->getId()]));

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken('disbursement_receipt' . $line->getId(), $request->request->getString('_token')))) {
            $session->getFlashBag()->add('danger', 'invoice.disbursement.receipt.flash.invalid_token');

            return $back;
        }

        $file = $request->files->get('receipt');

        if (! $file instanceof UploadedFile) {
            $session->getFlashBag()->add('warning', 'invoice.disbursement.receipt.flash.missing');

            return $back;
        }

        $violations = $this->validator->validate($file, new File(
            maxSize: DocumentStorage::MAX_SIZE,
            mimeTypes: array_keys(DocumentStorage::ALLOWED_TYPES),
        ));

        if ($violations->count() > 0) {
            $session->getFlashBag()->add('danger', 'invoice.disbursement.receipt.flash.rejected');

            return $back;
        }

        $receipt = DisbursementReceipt::of($this->storage->store($file, $invoice->getCompany()));
        $receipt->setCompany($invoice->getCompany());
        $line->addReceipt($receipt);

        $entityManager = $this->doctrine->getManager();
        $entityManager->persist($receipt);
        $entityManager->flush();

        $session->getFlashBag()->add('success', 'invoice.disbursement.receipt.flash.added');

        return $back;
    }

    /**
     * Only a disbursement on an invoice takes a receipt. A recurring invoice's
     * line is a template — each occurrence has its own supplier's document —
     * and a credit note gives an advance back rather than re-billing it.
     */
    public static function invoiceOf(Line $line): Invoice
    {
        $invoice = $line->getInvoice();

        if ($line instanceof RecurringInvoiceLine || $line instanceof CreditNoteLine || ! $line->isDisbursement() || ! $invoice instanceof Invoice) {
            throw new NotFoundHttpException('This line is not a disbursement on an invoice.');
        }

        return $invoice;
    }
}
