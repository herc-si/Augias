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

use Augias\CoreBundle\Entity\DocumentActivity;
use Augias\CoreBundle\Storage\DocumentStorage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function sprintf;

/**
 * The quote as the client accepted it, kept beside their answer. The company
 * filter keeps one tenant from another's proofs, as for any of its rows.
 */
final readonly class DownloadActivityProof
{
    public function __construct(
        private DocumentStorage $storage,
    ) {
    }

    public function __invoke(DocumentActivity $activity): Response
    {
        $proofPath = $activity->getProofPath();

        if (null === $proofPath) {
            throw new NotFoundHttpException('This line of the history keeps no document.');
        }

        try {
            $path = $this->storage->path($proofPath);
        } catch (RuntimeException) {
            throw new NotFoundHttpException('This document is no longer on disk.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('accord-%s.pdf', $activity->getId()),
        );
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
