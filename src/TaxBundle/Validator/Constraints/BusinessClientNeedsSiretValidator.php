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

namespace Augias\TaxBundle\Validator\Constraints;

use Augias\ClientBundle\Entity\Client;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Whether a client is a private individual decides how a sale to it is
 * declared: e-reported, or invoiced electronically. A business without a
 * SIRET would be neither, so it is not accepted.
 *
 * Not for a business abroad, which has no SIRET to give: one whose addresses
 * are all outside France.
 */
final class BusinessClientNeedsSiretValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof BusinessClientNeedsSiret) {
            throw new UnexpectedTypeException($constraint, BusinessClientNeedsSiret::class);
        }

        if ($value === null) {
            return;
        }

        if (! $value instanceof Client) {
            throw new UnexpectedValueException($value, Client::class);
        }

        // A pure supplier is never invoiced by this company.
        if (! $value->isClient() || ! $value->isCompany() || $this->isAbroad($value)) {
            return;
        }

        foreach ($value->getTaxIdentifiers() as $identifier) {
            if ($identifier->getLabel() === TaxIdentifierType::SIRET && ($identifier->getValue() ?? '') !== '') {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('taxIdentifiers')
            ->addViolation();
    }

    private function isAbroad(Client $client): bool
    {
        $abroad = false;

        foreach ($client->getAddresses() as $address) {
            $country = strtoupper(trim((string) $address->getCountry()));

            if ('FR' === $country) {
                return false;
            }

            $abroad = $abroad || '' !== $country;
        }

        return $abroad;
    }
}
