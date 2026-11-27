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

namespace Augias\ClientBundle\Registry;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function hash;
use function implode;
use function is_array;
use function is_string;
use function mb_strlen;
use function min;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strtoupper;
use function Symfony\Component\String\u;
use function trim;

/**
 * The French companies register, through the State's own search service
 * ("Recherche d'entreprises", recherche-entreprises.api.gouv.fr): free, no
 * key, fed by INSEE's Sirene base.
 *
 * Asked from the server rather than the browser, to keep within the service's
 * rate and to carry on when it is down: a failure answers nothing, and the
 * form is filled in by hand as before. Answers are kept an hour.
 *
 * Individual entrepreneurs who asked not to be listed (« diffusion
 * partielle ») are not found by their name, only by their SIREN or SIRET,
 * and then without name, street or postcode: the service writes
 * « [NON-DIFFUSIBLE] » in their place, which is never copied into a form.
 * The town is still given.
 *
 * A town, postcode or département typed after the name (« boulangerie
 * martin st etienne ») is read as such when the whole search finds nothing:
 * the service looks for every word in the name or in an address, so a word
 * it does not have there, an abbreviation or a town where the company has
 * no establishment empties the list (Rémi, 27/11/2026).
 *
 * @see \Augias\ClientBundle\Tests\Registry\CompanyRegistryTest
 */
final readonly class CompanyRegistry
{
    private const string URL = 'https://recherche-entreprises.api.gouv.fr/search';

    /** The State's list of towns (geo.api.gouv.fr), to read a town typed after a name. */
    private const string TOWNS_URL = 'https://geo.api.gouv.fr/communes';

    private const int RESULTS = 6;

    /** What the service writes in place of what it may not give. */
    private const array WITHHELD = ['[NON-DIFFUSIBLE]', '[ND]'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Companies matching a name, a SIREN or a SIRET; nothing under three
     * characters.
     *
     * @return list<RegistryCompany>
     */
    public function search(string $query): array
    {
        $query = trim((string) preg_replace('/\s+/', ' ', $query));

        if (mb_strlen($query) < 3) {
            return [];
        }

        // A SIRET is looked up by the SIREN it starts with.
        $digits = (string) preg_replace('/\D/', '', $query);
        if (preg_match('/^\d{9}(\d{5})?$/', $digits) === 1 && preg_match('/^[\d\s]+$/', $query) === 1) {
            $query = substr($digits, 0, 9);
        }

        return $this->cache->get('company_registry_' . hash('xxh128', $query), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(3600);

            return $this->ask(['q' => $query]) ?: $this->askWithPlace($query);
        });
    }

    /**
     * The company with this SIREN, or null when the register does not have it.
     */
    public function find(string $siren): ?RegistryCompany
    {
        foreach ($this->search($siren) as $company) {
            if ($company->siren === $siren) {
                return $company;
            }
        }

        return null;
    }

    /**
     * The last word or two of the query as a place, the rest as the name:
     * the longer place first, so « saint etienne » is not read as « etienne ».
     *
     * @return list<RegistryCompany>
     */
    private function askWithPlace(string $query): array
    {
        $words = explode(' ', $query);

        for ($length = min(2, count($words) - 1); $length >= 1; --$length) {
            $name = implode(' ', array_slice($words, 0, -$length));
            $place = implode(' ', array_slice($words, -$length));

            if (mb_strlen($name) < 3 || preg_match('/\d/', $name) === 1) {
                continue;
            }

            $filter = $this->placeFilter($place);

            if ($filter === null) {
                continue;
            }

            $found = $this->ask(['q' => $name] + $filter, true);

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * The search filter a place stands for: a postcode, a département, or a
     * town by its postcodes (all of Paris's, for Paris). Null when it is none.
     *
     * @return array<string, string>|null
     */
    private function placeFilter(string $place): ?array
    {
        if (preg_match('/^\d{5}$/', $place) === 1) {
            return ['code_postal' => $place];
        }

        if (preg_match('/^(\d{2}|2[ab]|97\d)$/i', $place) === 1) {
            return ['departement' => strtoupper($place)];
        }

        if (preg_match('/\d/', $place) === 1 || mb_strlen($place) < 2) {
            return null;
        }

        try {
            $towns = $this->httpClient->request('GET', self::TOWNS_URL, [
                'query' => ['nom' => self::townName($place), 'fields' => 'nom,codesPostaux', 'boost' => 'population', 'limit' => 5],
                'timeout' => 4,
                'headers' => ['Accept' => 'application/json'],
            ])->toArray();
        } catch (Throwable $e) {
            $this->logger->warning('Towns list unavailable', ['exception' => $e->getMessage()]);

            return null;
        }

        // The town of that very name, not one that only looks like it.
        foreach ($towns as $town) {
            if (is_array($town) && is_string($town['nom'] ?? null) && self::townName($town['nom']) === self::townName($place) && is_array($town['codesPostaux'] ?? null) && $town['codesPostaux'] !== []) {
                return ['code_postal' => implode(',', array_filter($town['codesPostaux'], is_string(...)))];
            }
        }

        return null;
    }

    /**
     * A town's name as compared: lower case, no accents, hyphens and
     * apostrophes as spaces, « st » and « ste » written out.
     */
    private static function townName(string $name): string
    {
        $name = u($name)->ascii()->lower()->replaceMatches('/[\s\'’-]+/', ' ')->trim()->toString();

        return (string) preg_replace(['/\bst\b/', '/\bste\b/'], ['saint', 'sainte'], $name);
    }

    /**
     * @param array<string, string> $query
     * @param bool                  $placed whether a place narrowed it: the
     *                                      establishment found is then in it
     *
     * @return list<RegistryCompany>
     */
    private function ask(array $query, bool $placed = false): array
    {
        try {
            $response = $this->httpClient->request('GET', self::URL, [
                'query' => $query + ['per_page' => self::RESULTS],
                'timeout' => 4,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $data = $response->toArray();
        } catch (Throwable $e) {
            $this->logger->warning('Company register unavailable', ['exception' => $e->getMessage()]);

            return [];
        }

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        return array_values(array_filter(array_map(fn (mixed $result): ?RegistryCompany => $this->company($result, $placed), $results)));
    }

    private function company(mixed $result, bool $placed): ?RegistryCompany
    {
        if (! is_array($result) || ! is_string($result['siren'] ?? null)) {
            return null;
        }

        $seat = is_array($result['siege'] ?? null) ? $result['siege'] : [];
        $name = self::text($result['nom_raison_sociale'] ?? null) ?? self::text($result['nom_complet'] ?? null);

        $street = implode(' ', array_filter([
            self::text($seat['numero_voie'] ?? null),
            self::text($seat['indice_repetition'] ?? null),
            self::text($seat['type_voie'] ?? null),
            self::text($seat['libelle_voie'] ?? null),
        ]));

        // Where the search found it, when a place narrowed it and that is not
        // the head office: the town typed to tell namesakes apart is seen.
        $matching = is_array($result['matching_etablissements'] ?? null) && is_array($result['matching_etablissements'][0] ?? null) ? $result['matching_etablissements'][0] : [];
        $foundIn = self::text($matching['libelle_commune'] ?? null);
        $city = self::text($seat['libelle_commune'] ?? null);

        return new RegistryCompany(
            name: $name,
            siren: $result['siren'],
            siret: self::text($seat['siret'] ?? null),
            street1: $street !== '' ? $street : null,
            street2: self::text($seat['complement_adresse'] ?? null),
            zip: self::text($seat['code_postal'] ?? null),
            city: $city,
            active: ($result['etat_administratif'] ?? 'A') === 'A',
            establishmentCity: $placed && $foundIn !== $city ? $foundIn : null,
        );
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // « [NON-DIFFUSIBLE] », alone or one per part of a street.
        $value = trim(str_replace(self::WITHHELD, '', $value));

        return $value !== '' ? $value : null;
    }
}
