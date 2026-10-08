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
 * The documents that open with default terms, each with its own pair: one
 * for a client that is a business, one for a private individual.
 */
enum TermsDocument: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';

    public function settingKey(bool $business): string
    {
        return $this->value . '/default_terms/' . ($business ? 'business' : 'individual');
    }

    /**
     * The suggested wording, in the company's language, seeded into the
     * setting for a new company.
     */
    public function suggestionKey(bool $business): string
    {
        return 'terms.suggested.' . $this->value . '.' . ($business ? 'business' : 'individual');
    }
}
