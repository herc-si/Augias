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

namespace Augias\BillBundle\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Entity\Company;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the supplier an incoming invoice comes from — by its tax identifier,
 * then by its name — before creating one, so that the same supplier's
 * invoices do not pile up duplicate records. Shared by the invoices received
 * through the platform and those imported from a Factur-X file.
 *
 * @see \Augias\BillBundle\Tests\Functional\BillFromReceiptTest
 */
final readonly class SupplierResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClientRepository $clientRepository,
    ) {
    }

    public function resolve(Company $company, ?string $name, ?string $identifier): Client
    {
        if ($identifier !== null) {
            $existing = $this->clientRepository->findOneByTaxIdentifierValue($company->getId(), $identifier);

            if ($existing instanceof Client) {
                $existing->setIsSupplier(true);

                return $existing;
            }
        }

        if ($name !== null) {
            $existing = $this->clientRepository->findOneByName($company->getId(), $name);

            if ($existing instanceof Client) {
                $existing->setIsSupplier(true);

                return $existing;
            }
        }

        $client = new Client();
        $client->setCompany($company)
            ->setName($name ?? 'Unknown supplier')
            ->setIsClient(false)
            ->setIsSupplier(true);

        if ($identifier !== null) {
            $taxIdentifier = new TaxIdentifier();
            $taxIdentifier->setCompany($company)
                ->setLabel('Tax ID')
                ->setValue($identifier);

            $client->addTaxIdentifier($taxIdentifier);
        }

        $this->entityManager->persist($client);

        return $client;
    }
}
