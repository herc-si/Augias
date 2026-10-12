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

use Augias\ClientBundle\Form\InlineClient;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @see \Augias\ClientBundle\Tests\Validator\Constraints\InlineClientRulesValidatorTest
 */
final class InlineClientRulesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CashRegisterGateInterface $cashRegisterGate,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof InlineClientRules) {
            throw new UnexpectedTypeException($constraint, InlineClientRules::class);
        }

        if (! $value instanceof InlineClient) {
            throw new UnexpectedValueException($value, InlineClient::class);
        }

        if (! $value->isNewClient()) {
            return;
        }

        if (! $value->isNewClientCompany()) {
            if ($this->cashRegisterGate->requiresBooks()) {
                $this->context->buildViolation($constraint->booksMessage)
                    ->atPath('newClientIsCompany')
                    ->addViolation();
            }

            return;
        }

        if ('' === trim((string) $value->getNewClientSiret())) {
            $this->context->buildViolation($constraint->siretMessage)
                ->atPath('newClientSiret')
                ->addViolation();
        }
    }
}
