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

namespace Augias\McpBundle\Security;

enum McpScope: string
{
    case Read = 'mcp:read';
    case Write = 'mcp:write';

    /**
     * The company's figures taken together: books, VAT, bank, turnover and
     * reports. Granted on its own on the consent page, unticked by default,
     * and implied by no other scope: an assistant that may write invoices
     * does not, for that, read the books.
     */
    case Finance = 'mcp:finance';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Write and finance each imply read; nothing implies finance. Check whether the given granted scopes satisfy a required scope.
     *
     * @param list<string> $grantedScopes
     */
    public static function satisfies(array $grantedScopes, self $required): bool
    {
        if (\in_array($required->value, $grantedScopes, true)) {
            return true;
        }

        return $required === self::Read
            && (\in_array(self::Write->value, $grantedScopes, true) || \in_array(self::Finance->value, $grantedScopes, true));
    }
}
