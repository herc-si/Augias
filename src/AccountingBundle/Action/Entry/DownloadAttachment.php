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

namespace Augias\AccountingBundle\Action\Entry;

use Augias\AccountingBundle\Entity\EntryAttachment;
use Augias\AccountingBundle\Service\AttachmentStorage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hands back a stored document.
 *
 * The file is served from here rather than from a public directory, so that
 * reaching it means holding a session for the company that owns it: the
 * multi-tenancy filter scopes the lookup, and an id belonging to another
 * company is simply not found. Documents in the books are not things to be
 * guessable by URL.
 *
 * Always as an attachment, never inline. A stored PDF is a document the user
 * asked for, and rendering one in the page would put a file this application
 * did not write inside its own origin.
 */
final readonly class DownloadAttachment
{
    public function __construct(
        private AttachmentStorage $storage,
    ) {
    }

    public function __invoke(EntryAttachment $attachment): Response
    {
        try {
            $path = $this->storage->path($attachment);
        } catch (RuntimeException) {
            // The row survived its file — a restore that put the database back
            // without the volume, most often. A 404 is the honest answer.
            throw new NotFoundHttpException('This document is no longer on disk.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getFilename(),
            // The fallback ASCII name a browser uses when it cannot read the
            // real one; the id can never contain anything a header dislikes.
            (string) $attachment->getId(),
        );
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
