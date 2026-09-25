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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use RuntimeException;
use Throwable;

/**
 * Thrown when the SuperPDP API rejects a request. Carries the `http_ko`
 * payload (see https://api.superpdp.tech/openapi/superpdp.json) so callers
 * can surface a meaningful message without depending on this class's
 * internals.
 */
final class SuperPdpApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $apiCode = null,
        ?Throwable $previous = null,
        private readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    /**
     * 403 is what SUPER PDP answers on every route but the session one while
     * the company behind the credentials is not verified.
     */
    public function isForbidden(): bool
    {
        return 403 === $this->httpStatus;
    }

    /** The credentials themselves were refused. */
    public function isUnauthorized(): bool
    {
        return 401 === $this->httpStatus;
    }
}
