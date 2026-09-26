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

namespace Augias\CoreBundle\Company;

use DateTimeImmutable;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class CompanyClosureEmail extends TemplatedEmail
{
    public function __construct(
        ClosureNotice $notice,
        string $companyName,
        ?DateTimeImmutable $closesAt,
        ?string $exportUrl,
        string $subject,
    ) {
        parent::__construct();

        $this->subject($subject);
        $this->htmlTemplate('@AugiasCore/Email/company_closure.html.twig');
        $this->textTemplate('@AugiasCore/Email/company_closure.text.twig');
        $this->context([
            'notice' => $notice->value,
            'companyName' => $companyName,
            'closesAt' => $closesAt,
            'exportUrl' => $exportUrl,
        ]);
    }
}
