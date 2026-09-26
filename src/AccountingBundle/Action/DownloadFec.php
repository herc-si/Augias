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

namespace Augias\AccountingBundle\Action;

use Augias\AccountingBundle\Fec\FecGenerator;
use Augias\AccountingBundle\Service\CurrentCompany;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands over the FEC of a financial year — or, with `notice`, the notice the
 * administration expects alongside it.
 */
final readonly class DownloadFec
{
    public function __construct(
        private FecGenerator $generator,
        private CurrentCompany $currentCompany,
    ) {
    }

    public function __invoke(int $year, string $part = 'file'): Response
    {
        $fec = $this->generator->generate($this->currentCompany->require(), $year);
        $notice = 'notice' === $part;

        return new Response(
            $notice ? $fec->notice : $fec->content,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $notice ? 'Notice_' . $fec->filename : $fec->filename,
                ),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
