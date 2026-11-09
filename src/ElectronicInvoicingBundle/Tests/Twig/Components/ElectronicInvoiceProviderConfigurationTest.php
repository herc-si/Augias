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

namespace Augias\ElectronicInvoicingBundle\Tests\Twig\Components;

use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpApplication;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\ElectronicInvoicingBundle\Twig\Components\ElectronicInvoiceProviderConfiguration;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_decode;
use function json_encode;
use function str_contains;

#[CoversClass(ElectronicInvoiceProviderConfiguration::class)]
final class ElectronicInvoiceProviderConfigurationTest extends LiveComponentTest
{
    /**
     * What the dev instance showed on 25/09/2026: the secret typed into the
     * page never reached the database.
     */
    public function testATypedSecretIsSaved(): void
    {
        $user = $this->getUser();
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($user->getCompanies()->first())
            ->setName('SUPERPDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'the-id', 'client_secret' => ''])
            ->setActive(true);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();

        // Saving asks SUPER PDP about the account; answered here, not over the network.
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['created_at' => '2026-09-25T12:00:00Z', 'company_verification_status' => 'needs_review'])),
        ])));

        $component = $this->createLiveComponent(
            name: ElectronicInvoiceProviderConfiguration::class,
            data: ['setting' => (string) $setting->getId(), 'provider' => 'super_pdp'],
            client: $this->client,
        )->actingAs($user);

        // As the browser does it: each field typed into is sent to the
        // component and re-rendered before the next, and only then is the
        // form saved.
        $component->set('einvoicing_provider_setting.settings.client_secret', 'typed-secret');
        $component->set('einvoicing_provider_setting.name', 'SUPERPDP');
        $component->call('save');

        $entityManager->clear();
        $saved = $entityManager->find(ElectronicInvoiceProviderSetting::class, $setting->getId());

        self::assertInstanceOf(ElectronicInvoiceProviderSetting::class, $saved);
        self::assertSame('typed-secret', $saved->getSettings()['client_secret'] ?? null);
    }

    /**
     * The regime SUPER PDP holds disagrees with Augias's: the button sets it,
     * and the warning goes once SUPER PDP holds the right one.
     */
    public function testTheVatSettingsAreAlignedAtSuperPdp(): void
    {
        $user = $this->getUser();
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($user->getCompanies()->first())
            ->setName('SUPERPDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'the-id', 'client_secret' => 'the-secret'])
            ->setActive(true);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');
        $config->set(SystemConfig::VAT_ON_DEBITS_CONFIG_PATH, '0');

        $regime = 'monthly';
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$regime): MockResponse {
                if ('PATCH' === $method) {
                    $regime = json_decode((string) $options['body'], true)['vat_regime'];
                }

                return new MockResponse((string) json_encode(match (true) {
                    str_contains($url, '/oauth2/token') => ['access_token' => 'a-token'],
                    str_contains($url, '/oauth2_sessions/me') => ['company_verification_status' => 'verified'],
                    default => ['formal_name' => 'Burger Queen', 'vat_regime' => $regime, 'has_vat_on_debits' => false],
                }));
            },
        )));

        $component = $this->createLiveComponent(
            name: ElectronicInvoiceProviderConfiguration::class,
            data: ['setting' => (string) $setting->getId(), 'provider' => 'super_pdp'],
            client: $this->client,
        )->actingAs($user);

        $component->call('checkAccount');
        self::assertStringContainsString('alignVatSettings', $component->render()->toString());

        $component->call('alignVatSettings');

        self::assertSame('vat_exemption', $regime);
        self::assertStringNotContainsString('alignVatSettings', $component->render()->toString());
    }

    /**
     * Not in the field, and not in the component's state either — both are in
     * the page source.
     */
    public function testTheSavedSecretIsNowhereInThePage(): void
    {
        $user = $this->getUser();
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($user->getCompanies()->first())
            ->setName('SUPERPDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'the-id', 'client_secret' => 'saved-secret-xyz']);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();

        $rendered = $this->createLiveComponent(
            name: ElectronicInvoiceProviderConfiguration::class,
            data: ['setting' => (string) $setting->getId(), 'provider' => 'super_pdp'],
            client: $this->client,
        )->actingAs($user)->render()->toString();

        self::assertStringContainsString('the-id', $rendered);
        self::assertStringNotContainsString('saved-secret-xyz', $rendered);
    }

    /**
     * With the deployment's own application, there is nothing to paste: the
     * setting is saved, and the user goes on to SUPER PDP to connect the
     * account — the platform is not asked about an account it has none for.
     */
    public function testASettingNotConnectedYetGoesOnToSuperPdp(): void
    {
        self::getContainer()->set(SuperPdpApplication::class, new SuperPdpApplication('app-id', 'app-secret'));
        // Nothing may reach SUPER PDP before the user has connected.
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient([])));

        $user = $this->getUser();
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($user->getCompanies()->first())
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings([]);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();

        $component = $this->createLiveComponent(
            name: ElectronicInvoiceProviderConfiguration::class,
            data: ['setting' => (string) $setting->getId(), 'provider' => 'super_pdp'],
            client: $this->client,
        )->actingAs($user);

        $rendered = $component->render()->toString();
        self::assertStringNotContainsString('client_secret', $rendered);
        self::assertStringContainsString('Not connected', $rendered);
        self::assertStringContainsString('/electronic-invoicing/super-pdp/connect/' . $setting->getId(), $rendered);

        $response = $component->call('save')->response();

        self::assertStringContainsString('/electronic-invoicing/super-pdp/connect/' . $setting->getId(), (string) $response->headers->get('Location') . (string) $response->headers->get('X-Live-Redirect'));

        $entityManager->clear();
        $saved = $entityManager->find(ElectronicInvoiceProviderSetting::class, $setting->getId());
        self::assertInstanceOf(ElectronicInvoiceProviderSetting::class, $saved);
        self::assertNull($saved->getAccountVerification());
    }
}
