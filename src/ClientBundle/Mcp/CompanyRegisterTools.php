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

use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Registry\RegistryCompany;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Security\McpScope;
use Mcp\Capability\Attribute\McpTool;
use function array_map;

/**
 * The French companies register, as the client form searches it: the
 * assistant had only the clients already there to look in, and answered
 * « not found » for a company that exists (Rémi, 27/11/2026).
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantTest::testTheAssistantSearchesTheCompaniesRegister
 */
final readonly class CompanyRegisterTools
{
    public function __construct(
        private CompanyRegistry $registry,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Companies of the French register matching a name, a SIREN or a SIRET.
     *
     * @param string $query Company name, SIREN (9 digits) or SIRET (14 digits); 3 characters at least
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'search_company_register', description: 'Search the French companies register by name (and town), SIREN or SIRET: name, siret, siren, vat_number and address to create a business client with. An unlisted sole trader has name null.')]
    #[McpScopeRequired(McpScope::Read)]
    public function searchCompanyRegister(string $query): array
    {
        $this->scopeGuard->require(McpScope::Read);

        $companies = $this->registry->search($query);

        return [
            'companies' => array_map(static fn (RegistryCompany $company): array => [
                'name' => $company->name,
                'siren' => $company->siren,
                'siret' => $company->siret,
                'vat_number' => $company->vatNumber(),
                'street1' => $company->street1,
                'street2' => $company->street2,
                'zip' => $company->zip,
                'city' => $company->city,
                'country' => 'FR',
                'active' => $company->active,
            ], $companies),
            'note' => $companies === []
                ? 'Nothing found. An individual entrepreneur may not be listed by name: ask for the SIREN, or create the client from what the person says.'
                : 'From the register; the VAT number is worked out from the SIREN, and an entrepreneur under the VAT exemption has none to use.',
        ];
    }
}
