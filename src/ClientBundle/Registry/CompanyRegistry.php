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
use function mb_strrpos;
use function mb_substr;
use function min;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strtoupper;
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
 * martin st etienne », « renault vienne ») is read first as a place, and
 * the query searched there, then the name alone; the whole query
 * everywhere only when that finds nothing.
 * The service looks for every word in the name or anywhere in an address:
 * an abbreviation or a town where the company has no establishment emptied
 * the list, and « vienne » found streets of that name all over France
 * (Rémi, 27/11/2026). A town is one of the State's towns by its exact name,
 * from the list kept with the application (see Towns).
 *
 * @see \Augias\ClientBundle\Tests\Registry\CompanyRegistryTest
 */
final readonly class CompanyRegistry
{
    private const string URL = 'https://recherche-entreprises.api.gouv.fr/search';

    private const int RESULTS = 6;

    /** What the service writes in place of what it may not give. */
    private const array WITHHELD = ['[NON-DIFFUSIBLE]', '[ND]'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private Towns $towns = new Towns(),
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

        // A SIREN or SIRET, spaces or not. A SIRET is that establishment, not
        // the head office: its own SIRET and address (Rémi, 27/11/2026: the
        // SIRET typed was replaced by the head office's, unseen, and that is
        // where an electronic invoice may be routed).
        $digits = (string) preg_replace('/\D/', '', $query);
        $siret = null;
        if (preg_match('/^\d{9}(\d{5})?$/', $digits) === 1 && preg_match('/^[\d\s]+$/', $query) === 1) {
            $query = $digits;
            $siret = mb_strlen($digits) === 14 ? $digits : null;
        }

        return $this->cache->get('company_registry_' . hash('xxh128', $query), function (ItemInterface $item) use ($query, $siret): array {
            $item->expiresAfter(3600);

            if ($siret !== null) {
                return $this->ask(['q' => $siret], siret: $siret);
            }

            return $this->askWithPlace($query) ?: $this->ask(['q' => $query]);
        });
    }

    /**
     * The establishment with this SIRET, as a company to fill in: its SIRET
     * and address; null when the register does not have it.
     */
    public function findEstablishment(string $siret): ?RegistryCompany
    {
        foreach ($this->search($siret) as $company) {
            if ($company->siret === $siret) {
                return $company;
            }
        }

        return null;
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

            // The whole query there first: the town may be part of the name
            // (« commune de bellegarde-poussieu » is not any town hall of
            // 38270, as app-test answered on 27/11/2026); then the name alone.
            $found = $this->ask(['q' => $query] + $filter, true) ?: $this->ask(['q' => $name] + $filter, true);

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

        $postcodes = $this->towns->postcodes($place);

        return $postcodes !== null ? ['code_postal' => $postcodes] : null;
    }

    /**
     * @param array<string, string> $query
     * @param bool                  $placed whether a place narrowed it: the
     *                                      establishment found is then in it
     * @param string|null           $siret  the establishment asked for
     *
     * @return list<RegistryCompany>
     */
    private function ask(array $query, bool $placed = false, ?string $siret = null): array
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

        return array_values(array_filter(array_map(fn (mixed $result): ?RegistryCompany => $this->company($result, $placed, $siret), $results)));
    }

    private function company(mixed $result, bool $placed, ?string $siret): ?RegistryCompany
    {
        if (! is_array($result) || ! is_string($result['siren'] ?? null)) {
            return null;
        }

        $seat = is_array($result['siege'] ?? null) ? $result['siege'] : [];
        $name = self::text($result['nom_raison_sociale'] ?? null) ?? self::text($result['nom_complet'] ?? null);

        if ($siret !== null && self::text($seat['siret'] ?? null) !== $siret) {
            return $this->establishment($result, $name, $siret);
        }

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

    /**
     * The establishment $siret of a company, not its head office. The
     * service gives its address on one line (« 2 RUE FERRER 42000
     * SAINT-ETIENNE »): the street is what comes before the postcode.
     *
     * @param array<mixed> $result
     */
    private function establishment(array $result, ?string $name, string $siret): ?RegistryCompany
    {
        $establishments = is_array($result['matching_etablissements'] ?? null) ? $result['matching_etablissements'] : [];

        foreach ($establishments as $establishment) {
            if (! is_array($establishment) || ($establishment['siret'] ?? null) !== $siret) {
                continue;
            }

            $zip = self::text($establishment['code_postal'] ?? null);
            $address = self::text($establishment['adresse'] ?? null) ?? '';
            $at = $zip !== null ? mb_strrpos($address, ' ' . $zip) : false;

            return new RegistryCompany(
                name: $name,
                siren: (string) $result['siren'],
                siret: $siret,
                street1: self::text($at !== false ? mb_substr($address, 0, $at) : null),
                street2: null,
                zip: $zip,
                city: self::text($establishment['libelle_commune'] ?? null),
                active: ($establishment['etat_administratif'] ?? 'A') === 'A',
                headOffice: false,
            );
        }

        // Not one of its establishments: nothing, rather than the head office.
        return null;
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
