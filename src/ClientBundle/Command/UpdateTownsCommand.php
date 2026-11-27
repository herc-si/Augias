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

namespace Augias\ClientBundle\Command;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use Augias\ClientBundle\Registry\Towns;
use SolidWorx\Platform\PlatformBundle\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function file_put_contents;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function sort;
use function sprintf;

/**
 * Writes the towns list the company register search reads a town from.
 * Run by hand when towns merge or are created (a few each year), and the
 * file committed.
 */
#[AsCommand(name: 'augias:registry:update-towns', description: 'Download the French towns and their postcodes from geo.api.gouv.fr for the company register search.')]
final class UpdateTownsCommand extends Command
{
    private const string URL = 'https://geo.api.gouv.fr/communes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $towns = $this->httpClient->request('GET', self::URL, [
            'query' => ['fields' => 'nom,codesPostaux', 'format' => 'json'],
            'timeout' => 120,
        ])->toArray();

        /** @var array<string, list<string>> $postcodes */
        $postcodes = [];

        foreach ($towns as $town) {
            if (! is_array($town) || ! is_string($town['nom'] ?? null) || ! is_array($town['codesPostaux'] ?? null)) {
                continue;
            }

            $codes = array_values(array_filter($town['codesPostaux'], is_string(...)));

            if ($codes !== []) {
                $name = Towns::normalise($town['nom']);
                $postcodes[$name] = array_merge($postcodes[$name] ?? [], $codes);
            }
        }

        if (count($postcodes) < 30000) {
            $this->io->error(sprintf('Only %d towns received: the file is left as it was.', count($postcodes)));

            return self::FAILURE;
        }

        ksort($postcodes);
        $file = [];

        foreach ($postcodes as $name => $codes) {
            $codes = array_values(array_unique($codes));
            sort($codes);
            $file[$name] = implode(',', $codes);
        }

        file_put_contents(Towns::FILE, json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->io->success(sprintf('%d town names written to %s.', count($file), Towns::FILE));

        return self::SUCCESS;
    }
}
