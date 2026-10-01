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

namespace Augias\SaasBundle\Support;

use Augias\CoreBundle\Entity\SupportRequest;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * One of the three moments of a request for help: asked for (to whoever
 * answers), taken and closed (to the company). One template, the moment
 * saying which.
 */
final class SupportEmail extends TemplatedEmail
{
    public function __construct(SupportNotice $notice, SupportRequest $request, ?string $provider, ?string $url, string $subject)
    {
        parent::__construct();

        $this->subject($subject);
        $this->htmlTemplate('@AugiasSaas/Email/support.html.twig');
        $this->textTemplate('@AugiasSaas/Email/support.text.twig');
        $this->context([
            'notice' => $notice->value,
            'provider' => $provider,
            'companyName' => (string) $request->getCompany()->getName(),
            'requestedBy' => $request->getRequestedBy(),
            'message' => $request->getMessage(),
            'expiresAt' => $request->getExpiresAt(),
            'operator' => $request->getOperator(),
            'note' => $request->getNote(),
            'url' => $url,
        ]);
    }
}
