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

namespace Augias\QuoteBundle\Email;

use Augias\CoreBundle\Activity\DocumentEmail;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Journal\Journalled;
use Augias\QuoteBundle\Entity\Quote;
use DateTimeInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * Sent to the client once they accept a quote from their link: what they
 * accepted, in whose name and when, with the quote attached as it stood.
 *
 * The acknowledgement of receipt a contract concluded online calls for
 * (C. civ. art. 1127-2), and the confirmation on a durable medium a consumer
 * is owed (C. conso. art. L221-13).
 */
final class QuoteAcceptedEmail extends TemplatedEmail implements DocumentEmail
{
    public function __construct(
        private readonly Quote $quote,
        string $signerName,
        DateTimeInterface $acceptedAt,
        string $sha256,
        string $pdf,
    ) {
        parent::__construct();

        $this->htmlTemplate('@AugiasQuote/Email/quote_accepted.html.twig');
        $this->context([
            'quote' => $quote,
            'signerName' => $signerName,
            'acceptedAt' => $acceptedAt,
            'sha256' => $sha256,
        ]);
        $this->attach($pdf, sprintf('devis-%s.pdf', $quote->getQuoteId()), 'application/pdf');
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function activityDocument(): Journalled
    {
        return $this->quote;
    }

    public function activityCompany(): Company
    {
        return $this->quote->getCompany();
    }

    public function activityDetail(): string
    {
        return 'acceptance_confirmation';
    }
}
