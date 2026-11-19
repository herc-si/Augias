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

namespace Augias\ClientBundle\Mcp;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

final readonly class ClientWriteTools
{
    public function __construct(
        private ClientRepository $clientRepository,
        private TaxIdentifierRepository $taxIdentifierRepository,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Add a new contact to an existing client.
     *
     * @param string      $client_id  Client ULID
     * @param string|null $first_name Contact first name
     * @param string|null $last_name  Contact last name
     * @param string      $email      Contact email (required, must be valid)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_contact', description: 'Add a new contact (email + optional name) to an existing client.')]
    #[McpScopeRequired(McpScope::Write)]
    public function addContact(string $client_id, string $email, ?string $first_name = null, ?string $last_name = null): array
    {
        $this->scopeGuard->require(McpScope::Write);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ToolCallException(sprintf('Invalid email address: %s.', $email));
        }

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $contact = new Contact();
        $contact->setEmail($email);
        $contact->setFirstName($first_name);
        $contact->setLastName($last_name);
        $contact->setClient($client);
        $contact->setCompany($client->getCompany());

        $client->addContact($contact);

        $this->entityManager->persist($contact);
        $this->entityManager->flush();

        return $this->normalizer->normalize($contact);
    }

    /**
     * Add a tax identifier (e.g. SIRET, SIREN, TVA intracommunautaire, RCS) to a client.
     *
     * @param string $client_id Client ULID
     * @param string $label     Identifier label (e.g. "SIRET", "SIREN", "TVA intracommunautaire", "RCS", "Code APE/NAF", "Autre")
     * @param string $value     Identifier value
     * @param bool   $primary   Whether this is the primary identifier for the client
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_client_tax_identifier', description: 'Add a tax identifier (SIRET, SIREN, TVA intracommunautaire, RCS, Code APE/NAF, Autre) to a client.')]
    #[McpScopeRequired(McpScope::Write)]
    public function addTaxIdentifier(string $client_id, string $label, string $value, bool $primary = false): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $label = trim($label);
        $value = trim($value);

        if ($label === '') {
            throw new ToolCallException('Tax identifier label cannot be empty.');
        }

        if ($value === '') {
            throw new ToolCallException('Tax identifier value cannot be empty.');
        }

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $identifier = new TaxIdentifier();
        $identifier->setLabel($label);
        $identifier->setValue($value);
        $identifier->setPrimary($primary);
        $identifier->setCompany($client->getCompany());

        $client->addTaxIdentifier($identifier);

        $this->entityManager->persist($identifier);
        $this->entityManager->flush();

        return $this->normalizer->normalize($identifier);
    }

    /**
     * Remove a tax identifier from a client.
     *
     * @param string $tax_identifier_id Tax identifier ULID
     *
     * @return array{removed: bool}
     */
    #[McpTool(name: 'remove_client_tax_identifier', description: 'Remove a tax identifier from a client by identifier ULID.')]
    #[McpScopeRequired(McpScope::Write)]
    public function removeTaxIdentifier(string $tax_identifier_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $identifier = $this->taxIdentifierRepository->find(UlidParser::parse($tax_identifier_id, 'tax_identifier_id'));

        if (! $identifier instanceof TaxIdentifier) {
            throw new ToolCallException(sprintf('Tax identifier %s not found.', $tax_identifier_id));
        }

        $this->entityManager->remove($identifier);
        $this->entityManager->flush();

        return ['removed' => true];
    }

    /**
     * Add a postal address to a client or a supplier. The first address is
     * the one printed on documents.
     *
     * @param string      $client_id Client or supplier ULID
     * @param string      $street1   Street and number
     * @param string      $city      City
     * @param string      $zip       Postal code
     * @param string      $country   ISO 3166-1 alpha-2 country code (e.g. FR)
     * @param string|null $street2   Second address line
     * @param string|null $state     Region or state, where one is used
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'add_client_address', description: 'Add a postal address to a client or supplier (country as an ISO code such as FR). The first address is the one printed on documents.')]
    #[McpScopeRequired(McpScope::Write)]
    public function addClientAddress(string $client_id, string $street1, string $city, string $zip, string $country = 'FR', ?string $street2 = null, ?string $state = null): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $client = $this->clientRepository->find(UlidParser::parse($client_id, 'client_id'));

        if (! $client instanceof Client) {
            throw new ToolCallException(sprintf('Client %s not found.', $client_id));
        }

        $address = new Address();
        $this->fillAddress($address, ['street1' => $street1, 'street2' => $street2, 'city' => $city, 'zip' => $zip, 'state' => $state, 'country' => $country]);
        $client->addAddress($address);

        $this->entityManager->persist($address);
        $this->entityManager->flush();

        return $this->normalizer->normalize($client);
    }

    /**
     * Change an address of a client or a supplier. Only the keys given change.
     *
     * @param string               $address_id Address ULID (from get_resource client)
     * @param array<string, mixed> $changes    Any of: street1, street2, city, zip, state, country
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_client_address', description: 'Change an address of a client or supplier: changes is an object with any of street1, street2, city, zip, state, country.')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateClientAddress(string $address_id, #[Schema(type: 'object', additionalProperties: true)] array $changes): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $address = $this->address($address_id);
        $this->fillAddress($address, $changes);
        $this->entityManager->flush();

        $client = $address->getClient();
        \assert($client instanceof Client);

        return $this->normalizer->normalize($client);
    }

    /**
     * Remove an address from a client or a supplier.
     *
     * @param string $address_id Address ULID
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'remove_client_address', description: 'Remove an address from a client or supplier.')]
    #[McpScopeRequired(McpScope::Write)]
    public function removeClientAddress(string $address_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $address = $this->address($address_id);
        $client = $address->getClient();
        \assert($client instanceof Client);

        $client->removeAddress($address);
        $this->entityManager->remove($address);
        $this->entityManager->flush();

        return $this->normalizer->normalize($client);
    }

    /**
     * An address is reached through its client, which the company filter
     * holds to the active company: one of another company is not found.
     */
    private function address(string $addressId): Address
    {
        $address = $this->entityManager->getRepository(Address::class)->find(UlidParser::parse($addressId, 'address_id'));
        $clientId = $address?->getClient()?->getId();

        if (! $address instanceof Address || $clientId === null || ! $this->clientRepository->find($clientId) instanceof Client) {
            throw new ToolCallException(sprintf('Address %s not found.', $addressId));
        }

        return $address;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function fillAddress(Address $address, array $fields): void
    {
        foreach ($fields as $field => $value) {
            if ($value !== null && ! \is_string($value)) {
                throw new ToolCallException(sprintf('%s must be a string.', (string) $field));
            }

            $value = $value === null ? null : trim($value);

            match ($field) {
                'street1' => $address->setStreet1($value),
                'street2' => $address->setStreet2($value),
                'city' => $address->setCity($value),
                'zip' => $address->setZip($value),
                'state' => $address->setState($value),
                'country' => $address->setCountry($value === null ? null : strtoupper($value)),
                default => throw new ToolCallException(sprintf('Unknown address field "%s". Accepted: street1, street2, city, zip, state, country.', (string) $field)),
            };
        }

        if ($address->getCountry() !== null && preg_match('/^[A-Z]{2}$/', $address->getCountry()) !== 1) {
            throw new ToolCallException('country must be an ISO 3166-1 alpha-2 code, such as FR or BE.');
        }
    }
}
