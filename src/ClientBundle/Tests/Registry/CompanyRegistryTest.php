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

namespace Augias\ClientBundle\Tests\Registry;

use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Registry\RegistryCompany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CompanyRegistry::class)]
#[CoversClass(RegistryCompany::class)]
final class CompanyRegistryTest extends TestCase
{
    /**
     * The shape the State's search service answers with (trimmed).
     */
    public const array DECATHLON = [
        'results' => [[
            'siren' => '306138900',
            'nom_complet' => 'DECATHLON (DECATHLON)',
            'nom_raison_sociale' => 'DECATHLON',
            'etat_administratif' => 'A',
            'siege' => [
                'siret' => '30613890001294',
                'numero_voie' => '4',
                'indice_repetition' => null,
                'type_voie' => 'BOULEVARD',
                'libelle_voie' => 'DE MONS',
                'complement_adresse' => null,
                'code_postal' => '59650',
                'libelle_commune' => "VILLENEUVE-D'ASCQ",
            ],
        ]],
        'total_results' => 1,
    ];

    public function testACompanyIsReadFromTheRegister(): void
    {
        $registry = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests);

        $companies = $registry->search('decathlon');

        self::assertCount(1, $companies);
        $company = $companies[0];
        self::assertSame('DECATHLON', $company->name);
        self::assertSame('306138900', $company->siren);
        self::assertSame('30613890001294', $company->siret);
        self::assertSame('4 BOULEVARD DE MONS', $company->street1);
        self::assertNull($company->street2);
        self::assertSame('59650', $company->zip);
        self::assertSame("VILLENEUVE-D'ASCQ", $company->city);
        self::assertTrue($company->active);
        self::assertStringContainsString('q=decathlon', $requests[0]);
    }

    /**
     * A town after the name that the whole search does not find (here
     * abbreviated) is read as a town: the name is searched in its postcodes.
     */
    public function testATownTypedAfterTheNameNarrowsTheSearch(): void
    {
        $registry = $this->registry([
            self::nothing(),
            new JsonMockResponse([['nom' => 'Saint-Étienne', 'codesPostaux' => ['42000', '42100']]]),
            new JsonMockResponse(self::DECATHLON),
        ], $requests);

        $companies = $registry->search('Decathlon st etienne');

        self::assertCount(1, $companies);
        self::assertStringContainsString('geo.api.gouv.fr', $requests[1]);
        self::assertStringContainsString('nom=saint%20etienne', $requests[1]);
        self::assertStringContainsString('q=Decathlon', $requests[2]);
        self::assertStringContainsString('code_postal=42000%2C42100', $requests[2]);
    }

    public function testAPostcodeOrADepartementAfterTheNameIsAFilter(): void
    {
        $registry = $this->registry([self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);
        self::assertCount(1, $registry->search('decathlon 59650'));
        self::assertStringContainsString('code_postal=59650', $requests[1]);

        // « grenoble 38 » is not a town, so it is not looked up: 38 is the département.
        $registry = $this->registry([self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);
        self::assertCount(1, $registry->search('decathlon grenoble 38'));
        self::assertStringContainsString('q=decathlon%20grenoble', $requests[1]);
        self::assertStringContainsString('departement=38', $requests[1]);
    }

    /**
     * A town that is not the one typed (the towns list answers fuzzily) is
     * not a filter, and the search answers nothing rather than elsewhere.
     */
    public function testATownOnlyLookingLikeTheOneTypedIsNotUsed(): void
    {
        $registry = $this->registry([self::nothing(), new JsonMockResponse([['nom' => 'Cognat-Lyonne', 'codesPostaux' => ['03110']]])], $requests);

        self::assertSame([], $registry->search('martin lyonn'));
        self::assertCount(2, $requests);
    }

    public function testTheEstablishmentFoundIsShownWhenNotTheHeadOffice(): void
    {
        $answer = self::DECATHLON;
        $answer['results'][0]['matching_etablissements'] = [['libelle_commune' => 'SAINT-ETIENNE']];
        $registry = $this->registry([self::nothing(), new JsonMockResponse([['nom' => 'Saint-Étienne', 'codesPostaux' => ['42000']]]), new JsonMockResponse($answer)], $requests);
        self::assertSame('SAINT-ETIENNE', $registry->search('decathlon saint-etienne')[0]->establishmentCity);

        // Without a place, the establishment matched may be anywhere: not shown.
        $registry = $this->registry([new JsonMockResponse($answer)], $requests);
        self::assertNull($registry->search('decathlon')[0]->establishmentCity);
    }

    /**
     * An individual entrepreneur who asked not to be listed (HERC SI, as the
     * service answered on 27/11/2026): found by SIREN only, and nothing of
     * « [NON-DIFFUSIBLE] » reaches a form. The town is public.
     */
    public function testWhatTheRegisterWithholdsIsLeftOut(): void
    {
        $withheld = '[NON-DIFFUSIBLE]';
        $registry = $this->registry([new JsonMockResponse(['results' => [[
            'siren' => '514072248',
            'nom_complet' => $withheld,
            'nom_raison_sociale' => $withheld,
            'statut_diffusion' => 'P',
            'etat_administratif' => 'A',
            'siege' => [
                'siret' => '51407224800024',
                'numero_voie' => $withheld,
                'indice_repetition' => null,
                'type_voie' => $withheld,
                'libelle_voie' => $withheld,
                'complement_adresse' => null,
                'code_postal' => $withheld,
                'libelle_commune' => 'BELLEGARDE-POUSSIEU',
            ],
        ]], 'total_results' => 1])], $requests);

        $company = $registry->find('514072248');

        self::assertNotNull($company);
        self::assertNull($company->name);
        self::assertNull($company->street1);
        self::assertNull($company->zip);
        self::assertSame('BELLEGARDE-POUSSIEU', $company->city);
        self::assertSame('51407224800024', $company->siret);
    }

    /**
     * The register gives no VAT number; the SIREN decides it. FR40303265045
     * is the example the tax administration itself gives.
     */
    public function testTheVatNumberFollowsFromTheSiren(): void
    {
        self::assertSame('FR40303265045', new RegistryCompany('X', '303265045', null, null, null, null, null, true)->vatNumber());
        self::assertSame('FR51306138900', new RegistryCompany('X', '306138900', null, null, null, null, null, true)->vatNumber());
    }

    public function testASiretIsLookedUpByItsSiren(): void
    {
        $registry = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests);

        self::assertNotNull($registry->find('306138900'));
        $registry2 = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests2);
        $registry2->search('306 138 900 01294');

        self::assertStringContainsString('q=306138900', $requests2[0]);
    }

    public function testShortQueriesAndOutagesAnswerNothing(): void
    {
        $registry = $this->registry([new MockResponse('', ['http_code' => 503])], $requests);

        self::assertSame([], $registry->search('ab'));
        self::assertSame([], $requests, 'Under three characters, nothing is asked.');
        self::assertSame([], $registry->search('decathlon'), 'The register down: the form is filled by hand.');
    }

    private static function nothing(): JsonMockResponse
    {
        return new JsonMockResponse(['results' => [], 'total_results' => 0]);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string>|null  $requests
     *
     * @param-out list<string> $requests
     */
    private function registry(array $responses, ?array &$requests): CompanyRegistry
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$responses, &$requests): MockResponse {
            $requests[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new CompanyRegistry($client, new ArrayAdapter(), new NullLogger());
    }
}
