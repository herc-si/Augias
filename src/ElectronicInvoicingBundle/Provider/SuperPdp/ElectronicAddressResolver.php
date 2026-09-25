<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Company;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use function ctype_digit;
use function in_array;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Finds where an e-invoice goes, so nobody has to type it: the seller's own
 * address from what the platform registered for it, the buyer's from the
 * French directory by SIREN.
 *
 * An address is where the invoice and every answer to it are delivered —
 * usually the SIREN, sometimes with a suffix per establishment or
 * department, and an answer sent to the wrong one has nowhere to go. What is
 * found is recorded as the "Adresse électronique" identifier, where the
 * e-invoice reads it, where the user sees it, and where they can correct it.
 * One already there is never touched: whoever typed it knew better.
 *
 * Nothing found is not an error: the e-invoice falls back on the SIREN, as it
 * always has. The sandbox's companies are not in the French directory, so a
 * buyer there still needs its address typed.
 */
final readonly class ElectronicAddressResolver
{
    private const string SCHEME = '0225';

    public function __construct(
        private SuperPdpClient $client,
        private TaxIdentifierRepository $taxIdentifiers,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $accessToken, Company $company, ?Client $buyer): void
    {
        $changed = $this->resolveOwn($accessToken, $company);

        if ($buyer instanceof Client) {
            $changed = $this->resolveBuyer($accessToken, $buyer) || $changed;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    private function resolveOwn(string $accessToken, Company $company): bool
    {
        $identifiers = $this->taxIdentifiers->findCompanyIdentifiers($company->getId());

        if (null !== $this->find($identifiers, TaxIdentifierType::ELECTRONIC_ADDRESS)) {
            return false;
        }

        $address = $this->choose(
            $this->lookup(fn (): array => $this->client->getOwnAddresses($accessToken)),
            $this->siren($identifiers),
            $this->find($identifiers, TaxIdentifierType::SIRET),
        );

        if (null === $address) {
            return false;
        }

        $identifier = new TaxIdentifier()->setLabel(TaxIdentifierType::ELECTRONIC_ADDRESS)->setValue($address);
        $identifier->setCompany($company);
        $this->entityManager->persist($identifier);

        return true;
    }

    private function resolveBuyer(string $accessToken, Client $buyer): bool
    {
        $identifiers = $buyer->getTaxIdentifiers()->toArray();

        if (null !== $this->find($identifiers, TaxIdentifierType::ELECTRONIC_ADDRESS)) {
            return false;
        }

        $siren = $this->siren($identifiers);

        if (null === $siren) {
            return false;
        }

        $address = $this->choose(
            $this->lookup(fn (): array => $this->client->findAddresses($accessToken, $siren)),
            $siren,
            $this->find($identifiers, TaxIdentifierType::SIRET),
        );

        if (null === $address) {
            return false;
        }

        $buyer->addTaxIdentifier(new TaxIdentifier()->setLabel(TaxIdentifierType::ELECTRONIC_ADDRESS)->setValue($address));

        return true;
    }

    /**
     * The establishment's own address when there is one for the SIRET, the
     * company's when there is one for the SIREN, the root the others extend
     * — and nothing when the choice would be a guess.
     *
     * @param list<string> $addresses "0225:…" identifiers
     */
    private function choose(array $addresses, ?string $siren, ?string $siret): ?string
    {
        $values = [];

        foreach ($addresses as $address) {
            if (str_starts_with($address, self::SCHEME . ':')) {
                $values[] = substr($address, strlen(self::SCHEME) + 1);
            }
        }

        if (null !== $siren && null !== $siret && in_array($siren . '_' . $siret, $values, true)) {
            return $siren . '_' . $siret;
        }

        if (null !== $siren && in_array($siren, $values, true)) {
            return $siren;
        }

        // The root the others extend — "315143296_92569" beside
        // "315143296_92569_replyto" and "…_demo1", as the sandbox registers
        // them. Several unrelated addresses leave nothing to choose from.
        foreach ($values as $candidate) {
            $isRoot = true;

            foreach ($values as $other) {
                if (! str_starts_with($other, $candidate)) {
                    $isRoot = false;

                    break;
                }
            }

            if ($isRoot) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param callable(): list<string> $lookup
     *
     * @return list<string>
     */
    private function lookup(callable $lookup): array
    {
        try {
            return $lookup();
        } catch (SuperPdpApiException $e) {
            // A directory that cannot be asked leaves the address as it was.
            $this->logger->warning('Could not look up an e-invoicing address.', ['exception' => $e]);

            return [];
        }
    }

    /**
     * The nine digits of the SIREN, from a SIREN or the start of a SIRET.
     *
     * @param array<TaxIdentifier> $identifiers
     */
    private function siren(array $identifiers): ?string
    {
        foreach ([TaxIdentifierType::SIREN, TaxIdentifierType::SIRET] as $label) {
            $digits = (string) preg_replace('/\D/', '', (string) $this->find($identifiers, $label));

            if (strlen($digits) >= 9 && ctype_digit($digits)) {
                return substr($digits, 0, 9);
            }
        }

        return null;
    }

    /**
     * @param array<TaxIdentifier> $identifiers
     */
    private function find(array $identifiers, string $label): ?string
    {
        foreach ($identifiers as $identifier) {
            $value = $identifier->getValue();

            if ($identifier->getLabel() === $label && null !== $value && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
