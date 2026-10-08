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
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class QuoteEmail extends TemplatedEmail implements DocumentEmail
{
    public function __construct(
        private readonly Quote $quote
    ) {
        parent::__construct();

        $this->htmlTemplate('@AugiasQuote/Email/quote.html.twig');
        $this->context(['quote' => $this->quote]);
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    public function activityDocument(): Journalled
    {
        return $this->getQuote();
    }

    public function activityCompany(): Company
    {
        return $this->getQuote()->getCompany();
    }

    public function activityDetail(): ?string
    {
        return null;
    }
}
