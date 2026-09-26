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

namespace Augias\CoreBundle\Exception;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A deletion refused because it would take an issued document with it.
 *
 * An invoice or a credit note that has gone to the client is part of the
 * books: the law says how long it is kept, and nothing in the application may
 * end that sooner. The message says what to do instead, so that whoever shows
 * it — a list, a page, the API — has something better than an error to show.
 */
final class DocumentMustBeKept extends RuntimeException implements TranslatableInterface
{
    /**
     * @param string                $reason     in English, for the API and the logs
     * @param array<string, string> $parameters
     */
    public function __construct(
        string $reason,
        private readonly string $messageKey,
        private readonly array $parameters = [],
    ) {
        parent::__construct($reason);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->messageKey, $this->parameters, null, $locale);
    }
}
