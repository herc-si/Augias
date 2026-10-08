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

namespace Augias\CoreBundle\Billing;

use Augias\ClientBundle\Entity\Client;
use Augias\SettingsBundle\SystemConfig;
use function str_replace;
use function trim;

/**
 * The terms a new invoice or quote opens with, from the settings: the law asks
 * different things of a document to a business (late payment penalties, the
 * €40 recovery fee, early payment discount) and to a private individual
 * (withdrawal period), so each client type has its own text.
 *
 * An empty setting means no default: the document opens without terms.
 *
 * @see \Augias\CoreBundle\Tests\Functional\DefaultTermsTest
 */
final readonly class DefaultTerms
{
    public function __construct(
        private SystemConfig $systemConfig,
    ) {
    }

    public function for(TermsDocument $document, bool $business): ?string
    {
        $terms = trim(self::normalise((string) $this->systemConfig->get($document->settingKey($business))));

        return '' === $terms ? null : $terms;
    }

    /**
     * A client not known yet, or typed in on the form, counts as a business:
     * the stricter text, and what a new client is by default.
     */
    public function forClient(TermsDocument $document, ?Client $client): ?string
    {
        return $this->for($document, ! $client instanceof Client || $client->isCompany());
    }

    /**
     * Whether these terms are one of the document's defaults, left as they
     * were: such terms may be replaced, terms someone wrote may not.
     */
    public function isDefault(TermsDocument $document, ?string $terms): bool
    {
        $terms = trim(self::normalise((string) $terms));

        if ('' === $terms) {
            return false;
        }

        return $terms === $this->for($document, true) || ($document->perClientType() && $terms === $this->for($document, false));
    }

    /**
     * A textarea posts "\r\n": the same text, whatever the line endings.
     */
    public static function normalise(string $terms): string
    {
        return str_replace("\r\n", "\n", $terms);
    }
}
