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

namespace Augias\ElectronicInvoicingBundle\Tests\Action;

use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Action\SyncNow;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoicingSync;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SyncNow::class)]
#[CoversClass(ElectronicInvoicingSync::class)]
final class SyncNowTest extends WebTestCase
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

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $company = $entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($company)
            ->setName('Test Provider')
            ->setProvider('test_provider')
            ->setSettings([])
            ->setActive(true);

        $entityManager->persist($setting);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(self::getContainer()->getParameter('kernel.project_dir') . '/var/cache/test/attachments/einvoicing');

        parent::tearDown();
    }

    /**
     * Just told an invoice is waiting, nobody should have to wait for the
     * hour: the inbox brings it in on demand, and says what it did.
     */
    public function testTheInboxBringsInWhatWasReceivedNow(): void
    {
        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Synchronise now')->form());

        self::assertResponseRedirects('/electronic-invoicing/incoming');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Synchronised: 1 invoice(s) received');
        self::assertCount(1, self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceReceipt::class)->findAll());
    }

    /**
     * Each run asks the platform about every invoice in progress: once a
     * minute is enough.
     */
    public function testNotTwiceInAMinute(): void
    {
        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming');
        $form = $crawler->selectButton('Synchronise now')->form();

        $this->client->submit($form);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Already synchronised less than a minute ago');
    }

    public function testAForgedRequestIsRefused(): void
    {
        $this->client->request('POST', '/electronic-invoicing/sync', ['_token' => 'forged', '_back' => 'https://evil.example/']);

        self::assertResponseRedirects('/electronic-invoicing/incoming');
        self::assertCount(0, self::getContainer()->get('doctrine')->getRepository(ElectronicInvoiceReceipt::class)->findAll());
    }
}
