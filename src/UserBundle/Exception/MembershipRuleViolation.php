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

namespace Augias\UserBundle\Exception;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A change to the members of a company that would break one of its rules —
 * leaving it without an owner, an administrator touching the owner.
 */
final class MembershipRuleViolation extends RuntimeException implements TranslatableInterface
{
    public function __construct(
        private readonly string $messageKey,
    ) {
        parent::__construct($messageKey);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->messageKey, [], null, $locale);
    }
}
