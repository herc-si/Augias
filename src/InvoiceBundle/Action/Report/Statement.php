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

namespace Augias\InvoiceBundle\Action\Report;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Response\PdfResponse;
use Augias\InvoiceBundle\Report\ClientStatement;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Environment;
use function max;
use function min;
use function sprintf;

/**
 * A client's statement of account, on screen or as a PDF to send them: for a
 * calendar year, with what was owed before it, or since the beginning.
 */
final readonly class Statement
{
    private const int FIRST_YEAR = 2000;

    public function __construct(
        private ClientStatement $statement,
        private Generator $pdfGenerator,
        private ClockInterface $clock,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, Client $client, string $_format = 'html'): Response
    {
        $today = $this->clock->now();
        $thisYear = (int) $today->format('Y');
        // No year, or "all": the whole history.
        $year = $request->query->has('year') && 'all' !== $request->query->get('year')
            ? max(self::FIRST_YEAR, min($thisYear, $request->query->getInt('year', $thisYear)))
            : null;

        $from = null === $year ? null : new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = null === $year ? null : new DateTimeImmutable(sprintf('%04d-12-31', $year));

        $context = [
            'client' => $client,
            'statement' => $this->statement->forClient($client, $from, $to),
            'year' => $year,
            'thisYear' => $thisYear,
            'today' => $today,
            'from' => $from,
            'to' => null === $to ? $today : min($to, $today),
        ];

        if ('pdf' === $_format) {
            $name = new AsciiSlugger()->slug((string) $client->getName())->lower()->toString();

            return new PdfResponse(
                $this->pdfGenerator->generate($this->twig->render('@AugiasInvoice/Report/statement_pdf.html.twig', $context)),
                sprintf('releve-%s-%s.pdf', '' === $name ? 'client' : $name, $year ?? $today->format('Y-m-d')),
            );
        }

        return new Response($this->twig->render('@AugiasInvoice/Report/statement.html.twig', $context));
    }
}
