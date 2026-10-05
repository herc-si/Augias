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
use function array_values;
use function hash;
use function implode;
use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function preg_replace;
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
 * Individual entrepreneurs who asked not to be listed are not in it.
 *
 * @see \Augias\ClientBundle\Tests\Registry\CompanyRegistryTest
 */
final readonly class CompanyRegistry
{
    private const string URL = 'https://recherche-entreprises.api.gouv.fr/search';

    private const int RESULTS = 6;

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

            return $this->ask($query);
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
     * @return list<RegistryCompany>
     */
    private function ask(string $query): array
    {
        try {
            $response = $this->httpClient->request('GET', self::URL, [
                'query' => ['q' => $query, 'per_page' => self::RESULTS],
                'timeout' => 4,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $data = $response->toArray();
        } catch (Throwable $e) {
            $this->logger->warning('Company register unavailable', ['exception' => $e->getMessage()]);

            return [];
        }

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        return array_values(array_filter(array_map($this->company(...), $results)));
    }

    private function company(mixed $result): ?RegistryCompany
    {
        if (! is_array($result) || ! is_string($result['siren'] ?? null)) {
            return null;
        }

        $seat = is_array($result['siege'] ?? null) ? $result['siege'] : [];
        $name = self::text($result['nom_raison_sociale'] ?? null) ?? self::text($result['nom_complet'] ?? null) ?? $result['siren'];

        $street = implode(' ', array_filter([
            self::text($seat['numero_voie'] ?? null),
            self::text($seat['indice_repetition'] ?? null),
            self::text($seat['type_voie'] ?? null),
            self::text($seat['libelle_voie'] ?? null),
        ]));

        return new RegistryCompany(
            name: $name,
            siren: $result['siren'],
            siret: self::text($seat['siret'] ?? null),
            street1: $street !== '' ? $street : null,
            street2: self::text($seat['complement_adresse'] ?? null),
            zip: self::text($seat['code_postal'] ?? null),
            city: self::text($seat['libelle_commune'] ?? null),
            active: ($result['etat_administratif'] ?? 'A') === 'A',
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
