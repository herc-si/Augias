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

namespace Augias\ClientBundle\Tests\Functional;

use Augias\ClientBundle\DataGrid\ClientGrid;
use Augias\ClientBundle\Enum\ClientStatus;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Clients and suppliers are one record but two entries in the sidebar: each
 * list shows its own side, and a record that is both shows in both.
 */
#[CoversClass(ClientGrid::class)]
final class ClientAndSupplierListsTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        ClientFactory::createOne(['name' => 'Seulement client', 'isClient' => true, 'isSupplier' => false, 'archived' => null, 'status' => ClientStatus::Active]);
        ClientFactory::createOne(['name' => 'Seulement fournisseur', 'isClient' => false, 'isSupplier' => true, 'archived' => null, 'status' => ClientStatus::Active]);
        ClientFactory::createOne(['name' => 'Les deux à la fois', 'isClient' => true, 'isSupplier' => true, 'archived' => null, 'status' => ClientStatus::Active]);
    }

    public function testTheClientListShowsClientsOnly(): void
    {
        $this->client->request('GET', '/clients/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Seulement client', $html);
        self::assertStringContainsString('Les deux à la fois', $html);
        self::assertStringNotContainsString('Seulement fournisseur', $html);
    }

    public function testTheSupplierListShowsSuppliersOnly(): void
    {
        $this->client->request('GET', '/suppliers/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Seulement fournisseur', $html);
        self::assertStringContainsString('Les deux à la fois', $html);
        self::assertStringNotContainsString('Seulement client', $html);
    }

    /**
     * The supplier form is handed a new record already marked as a supplier;
     * it used to read that as an edit and title the page "Edit … - ".
     */
    public function testAddingASupplierIsTitledAsSuch(): void
    {
        $this->client->request('GET', '/suppliers/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1.form-page-title', 'Add Supplier');
    }

    public function testEditingARecordIsTitledAsAnEdit(): void
    {
        $record = ClientFactory::createOne(['name' => 'Fiche existante', 'isClient' => true, 'archived' => null, 'status' => ClientStatus::Active]);

        $this->client->request('GET', '/clients/edit/' . $record->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1.form-page-title', 'Edit Record - Fiche existante');
    }
}
