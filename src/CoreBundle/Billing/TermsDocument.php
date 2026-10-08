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

/**
 * The documents that open with default terms. An invoice and a quote have a
 * pair, one for a client that is a business and one for a private individual:
 * the law asks different things of each. A credit note has a single text — it
 * says how the amount comes back, which is the same for everyone.
 */
enum TermsDocument: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case CreditNote = 'credit_note';

    public function perClientType(): bool
    {
        return self::CreditNote !== $this;
    }

    public function settingKey(bool $business = true): string
    {
        if (! $this->perClientType()) {
            return $this->value . '/default_terms';
        }

        return $this->value . '/default_terms/' . ($business ? 'business' : 'individual');
    }

    /**
     * The suggested wording, in the company's language, seeded into the
     * setting for a new company.
     */
    public function suggestionKey(bool $business = true): string
    {
        if (! $this->perClientType()) {
            return 'terms.suggested.' . $this->value;
        }

        return 'terms.suggested.' . $this->value . '.' . ($business ? 'business' : 'individual');
    }
}
