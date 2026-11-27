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

use const JSON_THROW_ON_ERROR;
use JsonException;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function preg_replace;
use function Symfony\Component\String\u;

/**
 * The French towns and their postcodes, kept with the application
 * (Resources/data/towns.json, made by augias:registry:update-towns from
 * geo.api.gouv.fr): the State's towns service took 6 to 8 seconds to answer
 * at times (27/11/2026), too long for a search typed in a form.
 *
 * Towns of the same name share their postcodes.
 *
 * @see \Augias\ClientBundle\Tests\Registry\TownsTest
 */
final class Towns
{
    public const string FILE = __DIR__ . '/../Resources/data/towns.json';

    /** @var array<string, string>|null */
    private ?array $postcodes = null;

    public function __construct(
        private readonly string $file = self::FILE,
    ) {
    }

    /**
     * The postcodes of the towns of that name, comma-separated, or null when
     * no town is called so.
     */
    public function postcodes(string $name): ?string
    {
        return $this->all()[self::normalise($name)] ?? null;
    }

    /**
     * A town's name as compared: lower case, no accents, hyphens and
     * apostrophes as spaces, « st » and « ste » written out.
     */
    public static function normalise(string $name): string
    {
        $name = u($name)->ascii()->lower()->replaceMatches('/[\s\'’-]+/', ' ')->trim()->toString();

        return (string) preg_replace(['/\bst\b/', '/\bste\b/'], ['saint', 'sainte'], $name);
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        if ($this->postcodes !== null) {
            return $this->postcodes;
        }

        $postcodes = [];

        try {
            $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true, 4, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            $data = [];
        }

        foreach (is_array($data) ? $data : [] as $name => $codes) {
            if (is_string($name) && is_string($codes)) {
                $postcodes[$name] = $codes;
            }
        }

        return $this->postcodes = $postcodes;
    }
}
