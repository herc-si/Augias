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

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;
use Augias\BillBundle\Entity\Bill;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function pathinfo;
use function realpath;
use function sprintf;
use function str_starts_with;

/**
 * Hands back the supplier's document attached to a bill. The path is read
 * off the bill, never the request, and must resolve inside the bills'
 * storage.
 */
final readonly class DownloadDocument
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(Bill $bill): BinaryFileResponse
    {
        $path = $bill->getDocumentPath();

        if (null === $path) {
            throw new NotFoundHttpException();
        }

        $root = realpath($this->projectDir . '/var/bills');
        $resolved = realpath($this->projectDir . '/' . $path);

        if (false === $root || false === $resolved || ! str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($resolved);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            sprintf('facture-%s.%s', $bill->getBillNumber() ?? $bill->getId(), pathinfo($resolved, PATHINFO_EXTENSION)),
            sprintf('invoice.%s', pathinfo($resolved, PATHINFO_EXTENSION)),
        );
        $response->headers->set('Content-Type', $bill->getDocumentMimeType() ?? 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
