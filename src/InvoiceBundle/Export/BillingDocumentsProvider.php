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

namespace Augias\InvoiceBundle\Export;

use Augias\CoreBundle\Export\Attachment\ExportAttachment;
use Augias\CoreBundle\Export\Attachment\ExportAttachmentProvider;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\CoreBundle\Templates\BillingTemplateChannel;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Listener\Doctrine\IssuedDocumentRetentionListener;
use Doctrine\ORM\EntityManagerInterface;
use Generator as PhpGenerator;
use RuntimeException;
use Twig\Environment;
use function file_get_contents;
use function preg_replace;

/**
 * The invoices and credit notes a company issued, as PDF, and the receipts
 * of what it advanced for its clients — the documents it has to keep, for
 * its export.
 *
 * Archived ones too: archiving hides a document, it does not end the time it
 * is kept for. Drafts are left out; they were never issued.
 */
final readonly class BillingDocumentsProvider implements ExportAttachmentProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Generator $pdf,
        private Environment $twig,
        private BillingTemplateResolver $templates,
        private DocumentStorage $storage,
    ) {
    }

    public function attachments(): PhpGenerator
    {
        $filters = $this->entityManager->getFilters();
        $archivable = $filters->isEnabled('archivable');

        if ($archivable) {
            $filters->disable('archivable');
        }

        try {
            if ($this->pdf->canPrintPdf()) {
                foreach ($this->entityManager->getRepository(Invoice::class)->findAll() as $invoice) {
                    if (IssuedDocumentRetentionListener::wasIssued($invoice)) {
                        yield new ExportAttachment(
                            'invoices/' . self::name($invoice->getInvoiceId()) . '.pdf',
                            fn (): string => $this->pdf->generate($this->twig->render($this->templates->resolve($invoice, BillingTemplateChannel::Pdf), ['invoice' => $invoice]), protect: false),
                        );
                    }
                }

                foreach ($this->entityManager->getRepository(CreditNote::class)->findAll() as $creditNote) {
                    if ($creditNote->isIssued()) {
                        yield new ExportAttachment(
                            'credit-notes/' . self::name($creditNote->getCreditNoteId()) . '.pdf',
                            fn (): string => $this->pdf->generate($this->twig->render('@AugiasInvoice/CreditNote/pdf.html.twig', ['creditNote' => $creditNote]), protect: false),
                        );
                    }
                }
            }

            foreach ($this->entityManager->getRepository(DisbursementReceipt::class)->findAll() as $receipt) {
                try {
                    $path = $this->storage->path($receipt->getStoragePath());
                } catch (RuntimeException) {
                    // The row outlived its file; there is nothing to hand over.
                    continue;
                }

                yield new ExportAttachment(
                    'disbursement-receipts/' . $receipt->getId() . '-' . self::name($receipt->getFilename()),
                    static fn (): string => (string) file_get_contents($path),
                );
            }
        } finally {
            if ($archivable) {
                $filters->enable('archivable');
            }
        }
    }

    private static function name(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    }
}
