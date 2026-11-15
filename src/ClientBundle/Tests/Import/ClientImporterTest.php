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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Import\ClientImporter;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function array_column;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * A client list brought in from a spreadsheet: what is valid goes in, what is
 * already there is left alone, what breaks a rule is reported with the rule.
 */
#[CoversClass(ClientImporter::class)]
#[Group('functional')]
final class ClientImporterTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private const string FILE = <<<'CSV'
        Nom;Type;SIRET;N° TVA;E-mail;Prénom;Nom du contact;Site web;Adresse;Code postal;Ville;Pays;Devise
        Atelier Dupont SARL;Entreprise;732 829 320 00074;fr44732829320;contact@dupont.example;Marie;Dupont;dupont.example;12 rue des Lilas;69003;Lyon;France;eur
        Jean Martin;Particulier;;;jean.martin@example.org;Jean;Martin;;5 place du Marché;31000;Toulouse;FR;
        Sans Siret SAS;Entreprise;;;;;;;;;;;
        Mauvais Mail;Particulier;;;pas-une-adresse;Paul;;;;;;;
        ;Particulier;;;;;;;;;;;
        CSV;

    public function testValidLinesBecomeClientsWithTheirContactAndAddress(): void
    {
        $this->underTheVatFranchise();

        $report = $this->importer()->import(self::FILE);

        self::assertSame(['Atelier Dupont SARL', 'Jean Martin'], $report->created);

        $this->em->clear();
        $dupont = $this->em->getRepository(Client::class)->findOneBy(['name' => 'Atelier Dupont SARL']);
        self::assertInstanceOf(Client::class, $dupont);
        self::assertTrue($dupont->isCompany());
        self::assertSame('73282932000074', $dupont->getSiret(), 'Spaces of the spreadsheet removed.');
        self::assertSame('FR44732829320', $dupont->getVatNumber());
        self::assertSame('https://dupont.example', $dupont->getWebsite());
        self::assertSame('EUR', $dupont->getCurrencyCode());
        self::assertCount(1, $dupont->getContacts());
        self::assertSame('contact@dupont.example', $dupont->getContacts()->first()->getEmail());
        self::assertSame('Marie', $dupont->getContacts()->first()->getFirstName());
        self::assertSame('Dupont', $dupont->getContacts()->first()->getLastName());
        self::assertCount(1, $dupont->getAddresses());
        self::assertSame('Lyon', $dupont->getAddresses()->first()->getCity());
        self::assertSame('FR', $dupont->getAddresses()->first()->getCountry(), 'A country name becomes its code.');

        $martin = $this->em->getRepository(Client::class)->findOneBy(['name' => 'Jean Martin']);
        self::assertInstanceOf(Client::class, $martin);
        self::assertFalse($martin->isCompany());
    }

    public function testLinesThatBreakARuleAreReportedAndTheRestGoesIn(): void
    {
        $this->underTheVatFranchise();

        $report = $this->importer()->import(self::FILE);

        self::assertSame([4, 5, 6], array_column($report->rejected, 'line'));
        self::assertSame('Sans Siret SAS', $report->rejected[0]['name']);
        self::assertNotSame([], $report->rejected[0]['errors'], 'A business needs a SIRET.');
        self::assertNotSame([], $report->rejected[1]['errors'], 'A malformed e-mail address.');
        self::assertSame('', $report->rejected[2]['name']);
        self::assertCount(2, $report->created);
    }

    public function testAClientAlreadyThereIsLeftAloneAndImportingTwiceChangesNothing(): void
    {
        $this->underTheVatFranchise();

        ClientFactory::createOne(['company' => $this->company, 'name' => 'jean  MARTIN']);

        $report = $this->importer()->import(self::FILE);
        self::assertSame([['line' => 3, 'name' => 'Jean Martin']], $report->duplicates);
        self::assertSame(['Atelier Dupont SARL'], $report->created);

        $again = $this->importer()->import(self::FILE);
        self::assertSame([], $again->created);
        self::assertCount(2, $again->duplicates);
    }

    public function testTheSameNameTwiceInOneFileIsCreatedOnce(): void
    {
        $this->underTheVatFranchise();

        $report = $this->importer()->import("Nom;Type\nJean Martin;Particulier\nJEAN MARTIN;Particulier\n");

        self::assertSame(['Jean Martin'], $report->created);
        self::assertSame([['line' => 3, 'name' => 'JEAN MARTIN']], $report->duplicates);
    }

    public function testAPreviewWritesNothing(): void
    {
        $this->underTheVatFranchise();

        $report = $this->importer()->import(self::FILE, dryRun: true);

        self::assertSame(['Atelier Dupont SARL', 'Jean Martin'], $report->created);
        self::assertSame(0, $this->em->getRepository(Client::class)->count([]));
    }

    public function testTheScreenPreviewsThenImports(): void
    {
        $this->underTheVatFranchise();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $path = (string) tempnam(sys_get_temp_dir(), 'clients');
        file_put_contents($path, self::FILE);

        $browser = $this->browser()
            ->actingAs($user)
            ->visit('/clients/')
            ->assertSuccessful()
            ->assertSeeElement('a[href$="/clients/import"]')
            ->visit('/clients/import')
            ->assertSuccessful()
            ->assertSeeElement('a[href$="/clients/import/clients.csv"]')
            ->attachFile('file', $path)
            ->click('form.client-import-upload button')
            ->assertSuccessful()
            ->assertSeeIn('.client-import-created', '2')
            ->assertSeeIn('.client-import-rejected', '3');

        self::assertSame(0, $this->em->getRepository(Client::class)->count([]), 'A preview: nothing is written yet.');

        $browser
            ->click('.client-import-report form button')
            ->assertSuccessful()
            ->assertSeeIn('.client-import-created', '2')
            ->assertSeeElement('.client-import-report a[href$="/clients/"]');

        self::assertSame(2, $this->em->getRepository(Client::class)->count([]));
    }

    public function testTheTemplateIsAFileTheImportReads(): void
    {
        $this->underTheVatFranchise();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $template = $this->browser()->actingAs($user)->visit('/clients/import/clients.csv')->assertSuccessful()->content();
        $report = $this->importer()->import($template, dryRun: true);

        self::assertSame(['Atelier Dupont SARL', 'Jean Martin'], $report->created);
        self::assertSame([], $report->rejected);
        self::assertSame([], $report->unknownColumns);
    }

    /**
     * A company liable to VAT that keeps no revenue book may not take a
     * private customer's payment (cash register rule), so the client form
     * refuses the customer: the import says the same thing.
     */
    public function testAPrivateCustomerIsRefusedWhereTheClientFormRefusesIt(): void
    {
        $report = $this->importer()->import("Nom;Type\nJean Martin;Particulier\n", dryRun: true);

        self::assertSame([], $report->created);
        self::assertSame('Jean Martin', $report->rejected[0]['name']);
        self::assertNotSame([], $report->rejected[0]['errors']);
    }

    /**
     * Under the franchise there is no cash register rule in the way of a private customer.
     */
    private function underTheVatFranchise(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');
    }

    private function importer(): ClientImporter
    {
        return self::getContainer()->get(ClientImporter::class);
    }
}
