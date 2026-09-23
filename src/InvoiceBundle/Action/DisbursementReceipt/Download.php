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
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hands a receipt back — to the user from the invoice, and to the client from
 * their copy of it, through {@see ClientDownload}.
 */
final readonly class Download
{
    public function __construct(
        private DocumentStorage $storage,
    ) {
    }

    public function __invoke(DisbursementReceipt $receipt): Response
    {
        try {
            $path = $this->storage->path($receipt->getStoragePath());
        } catch (RuntimeException) {
            // The row survived its file — a restore that put the database back
            // without the volume, most often. A 404 is the honest answer.
            throw new NotFoundHttpException('This document is no longer on disk.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $receipt->getFilename(),
            (string) $receipt->getId(),
        );
        $response->headers->set('Content-Type', $receipt->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
