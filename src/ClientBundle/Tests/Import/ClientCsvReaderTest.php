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

namespace Augias\ClientBundle\Tests\Import;

use Augias\ClientBundle\Import\ClientCsvReader;
use Augias\ClientBundle\Import\UnreadableClientFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_column;
use function implode;
use function mb_convert_encoding;
use function str_repeat;

/**
 * The file is read whatever wrote it: Excel on a French machine, a Google
 * Sheets export, another invoicing tool with its own column names.
 */
#[CoversClass(ClientCsvReader::class)]
final class ClientCsvReaderTest extends TestCase
{
    public function testReadsAnExcelExportWithSemicolonsAndAByteOrderMark(): void
    {
        $file = new ClientCsvReader()->read("\xEF\xBB\xBFNom;E-mail;Code postal;Ville\r\nAtelier Dupont;contact@dupont.example;69003;Lyon\r\n");

        self::assertSame([['line' => 2, 'data' => ['name' => 'Atelier Dupont', 'email' => 'contact@dupont.example', 'zip' => '69003', 'city' => 'Lyon']]], $file['rows']);
        self::assertSame([], $file['unknownColumns']);
    }

    public function testReadsCommasAndQuotedCells(): void
    {
        $file = new ClientCsvReader()->read("Company,Address,City\n\"Dupont, Martin & Fils\",\"12, rue des Lilas\",Lyon\n");

        self::assertSame(['name' => 'Dupont, Martin & Fils', 'street' => '12, rue des Lilas', 'city' => 'Lyon'], $file['rows'][0]['data']);
    }

    public function testReadsWindows1252AsExcelWritesIt(): void
    {
        $file = new ClientCsvReader()->read(mb_convert_encoding("Raison sociale;Prénom\nSociété Générale d'Édition;Hélène\n", 'Windows-1252', 'UTF-8'));

        self::assertSame(['name' => "Société Générale d'Édition", 'first_name' => 'Hélène'], $file['rows'][0]['data']);
    }

    public function testColumnTitlesAreRecognisedWhateverTheirCaseAndAccents(): void
    {
        $file = new ClientCsvReader()->read("NOM;N° TVA;Numéro de SIRET;Complément d'adresse;Nom du contact;Devise;Pays;Type;Site web\nA;B;C;D;E;F;G;H;I\n");

        self::assertSame(
            ['name' => 'A', 'vat_number' => 'B', 'siret' => 'C', 'street2' => 'D', 'last_name' => 'E', 'currency' => 'F', 'country' => 'G', 'type' => 'H', 'website' => 'I'],
            $file['rows'][0]['data'],
        );
    }

    public function testEveryTitleOfTheTemplateIsRead(): void
    {
        $header = ClientCsvReader::templateHeader();
        $file = new ClientCsvReader()->read(implode(';', $header) . "\n" . implode(';', $header) . "\n");

        self::assertSame([], $file['unknownColumns']);
        self::assertCount(14, $file['rows'][0]['data']);
    }

    public function testNamesTheColumnsItDidNotRead(): void
    {
        $file = new ClientCsvReader()->read("Nom;Téléphone;Notes internes\nA;0102030405;VIP\n");

        self::assertSame(['Téléphone', 'Notes internes'], $file['unknownColumns']);
        self::assertSame(['name' => 'A'], $file['rows'][0]['data']);
    }

    public function testBlankLinesAreSkippedAndLineNumbersKept(): void
    {
        $file = new ClientCsvReader()->read("Nom;Ville\nA;Lyon\n;\n\nB;Paris\n;;\n");

        self::assertSame([2, 5], array_column($file['rows'], 'line'));
    }

    public function testAFileWithoutANameColumnIsRefused(): void
    {
        $this->expectExceptionObject(new UnreadableClientFile(UnreadableClientFile::NO_NAME_COLUMN));

        new ClientCsvReader()->read("Référence;Montant\nF-1;120\n");
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectExceptionObject(new UnreadableClientFile(UnreadableClientFile::EMPTY));

        new ClientCsvReader()->read("Nom;Ville\n\n");
    }

    public function testAFileBeyondTheLimitIsRefused(): void
    {
        $this->expectExceptionObject(new UnreadableClientFile(UnreadableClientFile::TOO_MANY_ROWS));

        new ClientCsvReader()->read("Nom\n" . str_repeat("Client\n", ClientCsvReader::MAX_ROWS + 1));
    }
}
