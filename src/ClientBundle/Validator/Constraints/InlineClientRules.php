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

namespace Augias\ClientBundle\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * A client typed in on an invoice or a quote follows the rules of the client
 * form: a business gives its SIRET, and a private customer needs the books
 * kept where the company is VAT-registered.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InlineClientRules extends Constraint
{
    public string $siretMessage = 'client.constraint.tax_identifiers.siret_required';

    public string $booksMessage = 'client.constraint.private_customer_needs_books';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
