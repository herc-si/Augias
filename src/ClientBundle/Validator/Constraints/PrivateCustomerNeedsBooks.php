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
 * A private customer is taken on only by a company whose payments go straight
 * into its books — see {@see \Augias\CoreBundle\Contracts\CashRegisterGateInterface}.
 * Checked on the form and the API alike, as validation runs on both.
 *
 * @see \Augias\ClientBundle\Tests\Validator\Constraints\PrivateCustomerNeedsBooksValidatorTest
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PrivateCustomerNeedsBooks extends Constraint
{
    public string $message = 'client.constraint.private_customer_needs_books';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
