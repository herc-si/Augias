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

namespace Augias\AccountingBundle\Bank;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A file that could not be read as a statement, said in words the user can act on.
 */
final class UnreadableStatement extends RuntimeException implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $parameters = [],
    ) {
        parent::__construct($messageKey);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('accounting.bank.error.' . $this->messageKey, $this->parameters, null, $locale);
    }
}
