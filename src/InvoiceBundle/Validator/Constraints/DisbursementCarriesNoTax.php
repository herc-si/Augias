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

namespace Augias\InvoiceBundle\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * A line that re-bills money advanced in the client's name may carry no tax.
 *
 * The calculator already ignores any rate on such a line, so nothing wrong
 * reaches the totals. This exists so the user is told rather than quietly
 * overruled: a rate chosen and then silently dropped is the kind of thing that
 * is only noticed when a return has already been filed.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DisbursementCarriesNoTax extends Constraint
{
    public string $message = 'invoice.constraint.disbursement_carries_no_tax';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
