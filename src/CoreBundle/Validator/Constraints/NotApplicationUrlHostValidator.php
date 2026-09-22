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

namespace Augias\CoreBundle\Validator\Constraints;

use const PHP_URL_HOST;
use Augias\CoreBundle\Company\CompanyDomainResolver;
use Augias\CoreBundle\Entity\Company;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use function is_string;
use function parse_url;
use function rtrim;
use function strtolower;

/**
 * @see \Augias\CoreBundle\Tests\Validator\Constraints\NotApplicationUrlHostValidatorTest
 */
final class NotApplicationUrlHostValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CompanyDomainResolver $resolver,
        private readonly string $applicationUrl = '',
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof NotApplicationUrlHost) {
            throw new UnexpectedTypeException($constraint, NotApplicationUrlHost::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $candidate = Company::normalizeCustomDomain($value);

        // A host this deployment keeps for itself is refused whatever the
        // application URL says. The list and this check share one source, so a
        // name added to the reservations is out of reach from that moment —
        // rather than out of reach only for whoever has not claimed it yet.
        if ($candidate !== null && $this->resolver->isReserved($candidate)) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        if ($this->applicationUrl === '') {
            return;
        }

        $applicationHost = parse_url($this->applicationUrl, PHP_URL_HOST);

        if (! is_string($applicationHost) || $applicationHost === '') {
            return;
        }

        $applicationHost = rtrim(strtolower($applicationHost), '.');

        if ($candidate !== null && $candidate === $applicationHost) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
