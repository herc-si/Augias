<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManagerInterface;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which invoices go out electronically: those to a French company, named by
 * its SIRET or its SIREN. A client with neither is a private individual —
 * reported instead — and a VAT number alone is a company abroad.
 */
#[CoversClass(ElectronicInvoiceManager::class)]
final class ElectronicInvoiceEligibilityTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    /**
     * @return iterable<string, array{0: callable(Client): mixed, 1: bool}>
     */
    public static function clients(): iterable
    {
        yield 'SIRET' => [static fn (Client $client): Client => $client->setSiret('12345678900012'), true];
        yield 'SIREN alone' => [static fn (Client $client): Client => $client->setSiren('123456789'), true];
        yield 'VAT number alone' => [static fn (Client $client): Client => $client->setVatNumber('DE123456789'), false];
        yield 'nothing' => [static fn (Client $client): Client => $client, false];
    }

    /**
     * @param callable(Client): mixed $identify
     */
    #[DataProvider('clients')]
    public function testAnInvoiceGoesOutElectronicallyToAFrenchCompany(callable $identify, bool $eligible): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)->setName('SUPER PDP')->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])->setActive(true);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();

        $client = ClientFactory::createOne(['company' => $this->company]);
        $identify($client);

        $invoice = new Invoice();
        $invoice->setClient($client);

        self::assertSame($eligible, self::getContainer()->get(ElectronicInvoiceManagerInterface::class)->isEligible($invoice));
    }
}
