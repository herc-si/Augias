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
use Augias\ClientBundle\Registry\Towns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CompanyRegistry::class)]
#[CoversClass(RegistryCompany::class)]
#[CoversClass(Towns::class)]
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
     * A town after the name (here abbreviated) is read as a town first: the
     * name is searched in its postcodes, not the town's name in every street.
     */
    public function testATownTypedAfterTheNameNarrowsTheSearch(): void
    {
        $registry = $this->registry([self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);

        $companies = $registry->search('Decathlon st etienne');

        self::assertCount(1, $companies);
        self::assertCount(2, $requests);
        self::assertStringContainsString('q=Decathlon%20st%20etienne&', $requests[0]);
        self::assertStringContainsString('q=Decathlon&', $requests[1]);
        self::assertStringContainsString('code_postal=42000%2C42100', $requests[1]);
    }

    /**
     * The town may be part of the name: the whole query is searched in the
     * town first, not « commune de » among every town hall of its postcode.
     */
    public function testTheTownMayBePartOfTheName(): void
    {
        $registry = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests);

        self::assertCount(1, $registry->search('commune de vienne'));
        self::assertCount(1, $requests);
        self::assertStringContainsString('q=commune%20de%20vienne&', $requests[0]);
        self::assertStringContainsString('code_postal=38200', $requests[0]);
    }

    /**
     * Nothing of that name in the town: the whole query, as typed.
     */
    public function testNothingInTheTownSearchesTheWholeQuery(): void
    {
        $registry = $this->registry([self::nothing(), self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);

        self::assertCount(1, $registry->search('decathlon vienne'));
        self::assertStringContainsString('code_postal=38200', $requests[1]);
        self::assertStringContainsString('q=decathlon%20vienne', $requests[2]);
        self::assertStringNotContainsString('code_postal', $requests[2]);
    }

    public function testAPostcodeOrADepartementAfterTheNameIsAFilter(): void
    {
        $registry = $this->registry([self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);
        self::assertCount(1, $registry->search('decathlon 59650'));
        self::assertStringContainsString('q=decathlon&', $requests[1]);
        self::assertStringContainsString('code_postal=59650', $requests[1]);

        // « grenoble 38 » is not a town, so it is not looked up: 38 is the département.
        $registry = $this->registry([self::nothing(), new JsonMockResponse(self::DECATHLON)], $requests);
        self::assertCount(1, $registry->search('decathlon grenoble 38'));
        self::assertStringContainsString('q=decathlon%20grenoble&', $requests[1]);
        self::assertStringContainsString('departement=38', $requests[1]);
    }

    /**
     * A word that is no town is not a filter: the query is searched as typed.
     */
    public function testAWordThatIsNoTownIsNotUsed(): void
    {
        $registry = $this->registry([self::nothing()], $requests);

        self::assertSame([], $registry->search('martin lyonn'));
        self::assertCount(1, $requests);
        self::assertStringContainsString('q=martin%20lyonn', $requests[0]);
        self::assertStringNotContainsString('code_postal', $requests[0]);
    }

    public function testTheEstablishmentFoundIsShownWhenNotTheHeadOffice(): void
    {
        $answer = self::DECATHLON;
        $answer['results'][0]['matching_etablissements'] = [['libelle_commune' => 'SAINT-ETIENNE']];
        $registry = $this->registry([new JsonMockResponse($answer)], $requests);
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

    public function testTheHeadOfficeSiretIsTheHeadOffice(): void
    {
        $registry = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests);

        $company = $registry->findEstablishment('30613890001294');

        self::assertNotNull($company);
        self::assertTrue($company->headOffice);
        self::assertSame('4 BOULEVARD DE MONS', $company->street1);
        self::assertStringContainsString('q=30613890001294', $requests[0]);
    }

    /**
     * The SIRET of an establishment that is not the head office: that one,
     * its SIRET and address, spaces typed or not (Rémi, 27/11/2026: the
     * head office's replaced it, unseen).
     */
    public function testASiretIsThatEstablishmentNotTheHeadOffice(): void
    {
        $answer = self::DECATHLON;
        $answer['results'][0]['matching_etablissements'] = [[
            'siret' => '30613890003908',
            'adresse' => 'CENTRE COMMERCIAL STEEL 2 RUE FERRER 42000 SAINT-ETIENNE',
            'code_postal' => '42000',
            'libelle_commune' => 'SAINT-ETIENNE',
            'etat_administratif' => 'A',
            'est_siege' => false,
        ]];
        $registry = $this->registry([new JsonMockResponse($answer)], $requests);

        $companies = $registry->search('306 138 900 03908');

        self::assertCount(1, $companies);
        $company = $companies[0];
        self::assertFalse($company->headOffice);
        self::assertSame('DECATHLON', $company->name);
        self::assertSame('30613890003908', $company->siret);
        self::assertSame('CENTRE COMMERCIAL STEEL 2 RUE FERRER', $company->street1);
        self::assertSame('42000', $company->zip);
        self::assertSame('SAINT-ETIENNE', $company->city);
        self::assertStringContainsString('q=30613890003908', $requests[0]);
    }

    /**
     * A SIRET the register does not have: nothing, rather than the head
     * office of the SIREN it starts with.
     */
    public function testAnUnknownSiretFindsNothing(): void
    {
        $registry = $this->registry([new JsonMockResponse(self::DECATHLON)], $requests);

        self::assertNull($registry->findEstablishment('30613890099999'));
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

        return new CompanyRegistry($client, new ArrayAdapter(), new NullLogger(), new Towns(__DIR__ . '/towns.json'));
    }
}
