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

namespace Augias\ClientBundle\Import;

use Symfony\Component\String\Slugger\AsciiSlugger;
use function array_key_exists;
use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function in_array;
use function mb_check_encoding;
use function mb_convert_encoding;
use function rewind;
use function str_starts_with;
use function strtok;
use function substr;
use function substr_count;
use function trim;

/**
 * Reads a spreadsheet export of clients: one client per line, columns named in
 * the first line.
 *
 * Nothing about the file is asked of the user. Excel in France writes
 * semicolons and Windows-1252, a Google Sheets export commas and UTF-8, and
 * every tool names its columns its own way — "Raison sociale", "Company",
 * "Code postal", "Zip": the separator, the encoding and the column names are
 * worked out here.
 */
final class ClientCsvReader
{
    /** Beyond this the file is somebody's whole database, not a client list. */
    public const int MAX_ROWS = 2000;

    public const string NAME = 'name';

    public const string TYPE = 'type';

    public const string SIRET = 'siret';

    public const string VAT_NUMBER = 'vat_number';

    public const string EMAIL = 'email';

    public const string FIRST_NAME = 'first_name';

    public const string LAST_NAME = 'last_name';

    public const string WEBSITE = 'website';

    public const string STREET = 'street';

    public const string STREET2 = 'street2';

    public const string ZIP = 'zip';

    public const string CITY = 'city';

    public const string COUNTRY = 'country';

    public const string CURRENCY = 'currency';

    /**
     * Column names as people write them, once lower-cased and stripped of
     * accents and punctuation. The first name of each list is the one the
     * template file uses.
     *
     * @var array<string, list<string>>
     */
    private const array ALIASES = [
        self::NAME => ['nom', 'name', 'client', 'nom du client', 'societe', 'raison sociale', 'denomination', 'company', 'company name', 'client name'],
        self::TYPE => ['type', 'nature', 'type de client', 'client type'],
        self::SIRET => ['siret', 'n siret', 'no siret', 'numero siret', 'numero de siret'],
        self::VAT_NUMBER => ['n tva', 'tva', 'no tva', 'numero tva', 'numero de tva', 'tva intracommunautaire', 'n tva intracommunautaire', 'numero de tva intracommunautaire', 'vat', 'vat number', 'vat id'],
        self::EMAIL => ['e mail', 'email', 'mail', 'courriel', 'adresse e mail', 'adresse email', 'contact email', 'email address'],
        self::FIRST_NAME => ['prenom', 'prenom du contact', 'first name', 'firstname', 'contact first name'],
        self::LAST_NAME => ['nom du contact', 'nom contact', 'nom de famille', 'last name', 'lastname', 'surname', 'contact last name'],
        self::WEBSITE => ['site web', 'site', 'site internet', 'website', 'web', 'url'],
        self::STREET => ['adresse', 'rue', 'adresse 1', 'address', 'address 1', 'street', 'street 1', 'street1'],
        self::STREET2 => ['complement d adresse', 'complement', 'adresse 2', 'address 2', 'street 2', 'street2'],
        self::ZIP => ['code postal', 'cp', 'zip', 'zip code', 'postal code', 'postcode'],
        self::CITY => ['ville', 'commune', 'city', 'town'],
        self::COUNTRY => ['pays', 'country'],
        self::CURRENCY => ['devise', 'currency'],
    ];

    /**
     * The header line of the template offered for download.
     *
     * @return list<string>
     */
    public static function templateHeader(): array
    {
        return ['Nom', 'Type', 'SIRET', 'N° TVA', 'E-mail', 'Prénom', 'Nom du contact', 'Site web', 'Adresse', "Complément d'adresse", 'Code postal', 'Ville', 'Pays', 'Devise'];
    }

    /**
     * @return array{rows: list<array{line: int, data: array<string, string>}>, unknownColumns: list<string>}
     *
     * @throws UnreadableClientFile
     */
    public function read(string $content): array
    {
        $content = $this->toUtf8($content);

        if ('' === trim($content)) {
            throw new UnreadableClientFile(UnreadableClientFile::EMPTY);
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        $separator = $this->separator((string) strtok($content, "\r\n"));
        $header = fgetcsv($stream, null, $separator, '"', '');

        if (! $header) {
            fclose($stream);

            throw new UnreadableClientFile(UnreadableClientFile::EMPTY);
        }

        [$columns, $unknown] = $this->columns($header);

        if (! in_array(self::NAME, $columns, true)) {
            fclose($stream);

            throw new UnreadableClientFile(UnreadableClientFile::NO_NAME_COLUMN);
        }

        $rows = [];
        $line = 1;

        while (false !== ($cells = fgetcsv($stream, null, $separator, '"', ''))) {
            ++$line;
            $data = [];

            foreach ($columns as $index => $column) {
                $value = trim((string) ($cells[$index] ?? ''));

                // Two columns may map to the same field ("Adresse", "Rue"): the first filled one wins.
                if (null !== $column && '' !== $value && ! array_key_exists($column, $data)) {
                    $data[$column] = $value;
                }
            }

            // A line with nothing we read on it: the blank lines a spreadsheet leaves at the end.
            if ([] === $data) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($stream);

                throw new UnreadableClientFile(UnreadableClientFile::TOO_MANY_ROWS);
            }

            $rows[] = ['line' => $line, 'data' => $data];
        }

        fclose($stream);

        if ([] === $rows) {
            throw new UnreadableClientFile(UnreadableClientFile::EMPTY);
        }

        return ['rows' => $rows, 'unknownColumns' => $unknown];
    }

    /**
     * UTF-8 as is (minus the byte order mark Excel puts in front); anything
     * else is taken for Windows-1252, which is what Excel writes on a French
     * machine and a superset of Latin-1.
     */
    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * The separator is the candidate the header line holds most of.
     */
    private function separator(string $headerLine): string
    {
        $best = ';';
        $most = 0;

        foreach ([';', ',', "\t"] as $candidate) {
            $found = substr_count($headerLine, $candidate);

            if ($found > $most) {
                $best = $candidate;
                $most = $found;
            }
        }

        return $best;
    }

    /**
     * @param array<int, string|null> $header
     *
     * @return array{array<int, string|null>, list<string>}
     */
    private function columns(array $header): array
    {
        $slugger = new AsciiSlugger();
        $columns = [];
        $unknown = [];

        foreach ($header as $index => $title) {
            $title = trim((string) $title);
            $key = $slugger->slug($title, ' ')->lower()->toString();
            $columns[$index] = null;

            foreach (self::ALIASES as $column => $aliases) {
                if (in_array($key, $aliases, true)) {
                    $columns[$index] = $column;

                    break;
                }
            }

            if (null === $columns[$index] && '' !== $title) {
                $unknown[] = $title;
            }
        }

        return [$columns, $unknown];
    }
}
