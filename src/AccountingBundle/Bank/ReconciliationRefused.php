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
 * A match that cannot be made, said in words the user can act on.
 */
final class ReconciliationRefused extends RuntimeException implements TranslatableInterface
{
    public function __construct(
        private readonly string $reason,
    ) {
        parent::__construct($reason);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('accounting.bank.refused.' . $this->reason, [], null, $locale);
    }
}
