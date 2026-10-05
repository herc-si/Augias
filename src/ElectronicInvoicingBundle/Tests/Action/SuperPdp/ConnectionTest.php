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

namespace Augias\ElectronicInvoicingBundle\Tests\Action\SuperPdp;

use const PHP_URL_QUERY;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Action\SuperPdp\Callback;
use Augias\ElectronicInvoicingBundle\Action\SuperPdp\Connect;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApplication;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_ends_with;

/**
 * The whole round trip, as the browser makes it: off to SUPER PDP, and back
 * with a code.
 */
#[CoversClass(Connect::class)]
#[CoversClass(Callback::class)]
final class ConnectionTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company], 'email' => 'owner@example.com']);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testTheCompanyConnectsItsAccount(): void
    {
        $this->deploymentHasAnApplication();
        $setting = $this->setting();

        $this->client->request('GET', '/electronic-invoicing/super-pdp/connect/' . $setting->getId());

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://api.superpdp.tech/oauth2/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('owner@example.com', $query['login_hint']);

        $this->client->request('GET', '/electronic-invoicing/super-pdp/callback', ['state' => $query['state'], 'code' => 'the-code']);

        self::assertResponseRedirects('/electronic-invoicing/providers');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'SUPER PDP account connected.');

        $this->entityManager->clear();
        $saved = $this->entityManager->find(ElectronicInvoiceProviderSetting::class, $setting->getId());
        self::assertInstanceOf(ElectronicInvoiceProviderSetting::class, $saved);
        self::assertArrayHasKey('authorization', $saved->getSettings());
        // The first platform set up is the one invoices go to, and SUPER PDP
        // was asked at once whether the company is verified.
        self::assertTrue($saved->isActive());
        self::assertSame(AccountVerification::Verified, $saved->getAccountVerification());
    }

    public function testDecliningOnSuperPdpChangesNothing(): void
    {
        $this->deploymentHasAnApplication();
        $setting = $this->setting();

        $this->client->request('GET', '/electronic-invoicing/super-pdp/callback', ['error' => 'access_denied', 'state' => 'whatever']);

        self::assertResponseRedirects('/electronic-invoicing/providers');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Connecting to SUPER PDP was cancelled');

        $this->entityManager->clear();
        $saved = $this->entityManager->find(ElectronicInvoiceProviderSetting::class, $setting->getId());
        self::assertInstanceOf(ElectronicInvoiceProviderSetting::class, $saved);
        self::assertSame([], $saved->getSettings());
    }

    /**
     * A code that comes back without the session that asked for it — a link
     * sent to someone else, say — is not taken.
     */
    public function testACallbackNobodyStartedIsRefused(): void
    {
        $this->deploymentHasAnApplication();

        $this->client->request('GET', '/electronic-invoicing/super-pdp/callback', ['state' => 'forged', 'code' => 'the-code']);

        self::assertResponseRedirects('/electronic-invoicing/providers');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'This SUPER PDP connection has expired or was already used.');
    }

    /**
     * Without an application of the deployment's own, there is nothing to
     * connect to: credentials are pasted, as before.
     */
    public function testThereIsNothingToConnectToWithoutAnApplication(): void
    {
        $setting = $this->setting();

        $this->client->request('GET', '/electronic-invoicing/super-pdp/connect/' . $setting->getId());

        self::assertResponseStatusCodeSame(404);
    }

    private function deploymentHasAnApplication(): void
    {
        self::getContainer()->set(SuperPdpApplication::class, new SuperPdpApplication('app-id', 'app-secret'));
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(
            static fn (string $method, string $url): MockResponse => new MockResponse((string) json_encode(match (true) {
                str_ends_with($url, '/oauth2/token') => ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 1800],
                str_ends_with($url, '/oauth2_sessions/me') => ['company_verification_status' => 'verified'],
                default => ['formal_name' => 'Acme', 'env' => 'sandbox', 'has_vat_on_debits' => false, 'vat_regime' => 'monthly'],
            })),
        )));
    }

    private function setting(): ElectronicInvoiceProviderSetting
    {
        $setting = new ElectronicInvoiceProviderSetting()
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings([]);
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $setting->setCompany($company);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        return $setting;
    }
}
