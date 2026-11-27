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

namespace Augias\ElectronicInvoicingBundle\Tests\Functional;

use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Tests\Registry\CompanyRegistryTest;
use Augias\ElectronicInvoicingBundle\Action\Setup\Index;
use Augias\ElectronicInvoicingBundle\Onboarding\ElectronicInvoicingOnboarding;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use function json_decode;
use function ksort;
use function str_contains;
use function urlencode;

#[CoversClass(Index::class)]
#[CoversClass(ElectronicInvoicingOnboarding::class)]
final class SetupTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string URL = '/electronic-invoicing/setup';

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

        self::getContainer()->set(CompanyRegistry::class, new CompanyRegistry(
            // An establishment asked for by its SIRET is answered with it.
            new MockHttpClient(static function (string $method, string $url): JsonMockResponse {
                $answer = CompanyRegistryTest::DECATHLON;

                if (str_contains($url, '30613890003908')) {
                    $answer['results'][0]['matching_etablissements'] = [[
                        'siret' => '30613890003908',
                        'adresse' => '2 RUE FERRER 42000 SAINT-ETIENNE',
                        'code_postal' => '42000',
                        'libelle_commune' => 'SAINT-ETIENNE',
                        'etat_administratif' => 'A',
                    ]];
                }

                return new JsonMockResponse($answer);
            }),
            new ArrayAdapter(),
            new NullLogger(),
        ));
    }

    public function testLookingUpTheSirenFillsTheFormFromTheRegister(): void
    {
        $crawler = $this->client->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Look up')->form(['company_identity[siren]' => '306 138 900']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'DECATHLON');
        self::assertSame('30613890001294', $crawler->filter('#company_identity_siret')->attr('value'));
        self::assertSame('59650', $crawler->filter('#company_identity_zip')->attr('value'));
        self::assertSame('FR51306138900', $crawler->filter('#company_identity_vatNumber')->attr('value'));
        self::assertCount(0, $this->identifiers(), 'Looking up saves nothing.');
    }

    /**
     * A SIRET typed that is one of the SIREN's establishments is kept, with
     * that establishment's address: it is the one that invoices.
     */
    public function testLookingUpKeepsTheSiretTyped(): void
    {
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->selectButton('Look up')->form(['company_identity[siren]' => '306138900', 'company_identity[siret]' => '306 138 900 03908']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame('30613890003908', $crawler->filter('#company_identity_siret')->attr('value'));
        self::assertSame('2 RUE FERRER', $crawler->filter('#company_identity_street1')->attr('value'));
        self::assertSame('42000', $crawler->filter('#company_identity_zip')->attr('value'));
    }

    public function testSavingWritesTheCompanyWhereTheSettingsKeepItAndSwitchesElectronicInvoicingOn(): void
    {
        $crawler = $this->client->request('GET', self::URL);

        $this->client->submit($crawler->selectButton('Save')->form([
            'company_identity[siren]' => '306138900',
            'company_identity[siret]' => '30613890001294',
            'company_identity[vatExempt]' => '1',
            'company_identity[vatNumber]' => '',
            'company_identity[street1]' => '4 boulevard de Mons',
            'company_identity[zip]' => '59650',
            'company_identity[city]' => "Villeneuve-d'Ascq",
        ]));

        self::assertResponseRedirects(self::URL);

        self::assertSame(['SIREN' => '306138900', 'SIRET' => '30613890001294'], $this->identifiers());

        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);
        self::assertTrue($config->isVatExempt());
        self::assertSame('1', $config->get(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH));
        $address = json_decode((string) $config->get(ElectronicInvoicingOnboarding::ADDRESS_CONFIG_PATH), true);
        self::assertIsArray($address);
        self::assertSame('59650', $address['zip']);
        self::assertSame('FR', $address['country']);

        $this->client->followRedirect();
        self::assertSelectorTextContains('#identity', 'are saved');
    }

    public function testASiretThatIsNotTheSirensIsRefused(): void
    {
        $crawler = $this->client->request('GET', self::URL);

        $this->client->submit($crawler->selectButton('Save')->form([
            'company_identity[siren]' => '306138900',
            'company_identity[siret]' => '12345678900012',
            'company_identity[vatExempt]' => '1',
            'company_identity[zip]' => '59650',
            'company_identity[city]' => 'Lille',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#identity', 'does not start with your SIREN');
        self::assertCount(0, $this->identifiers());
    }

    public function testOnceSavedItGoesWhereItWasAskedFrom(): void
    {
        $crawler = $this->client->request('GET', self::URL . '?next=' . urlencode('/billing/checkout?plan=price_1'));

        $this->client->submit($crawler->selectButton('Save and continue to payment')->form([
            'company_identity[siren]' => '306138900',
            'company_identity[siret]' => '30613890001294',
            'company_identity[vatExempt]' => '0',
            'company_identity[vatNumber]' => 'FR51306138900',
            'company_identity[zip]' => '59650',
            'company_identity[city]' => 'Lille',
        ]));

        self::assertResponseRedirects('/billing/checkout?plan=price_1');
    }

    public function testNextNeverLeavesTheApplication(): void
    {
        $crawler = $this->client->request('GET', self::URL . '?next=' . urlencode('//evil.example/x'));

        $this->client->submit($crawler->selectButton('Save')->form([
            'company_identity[siren]' => '306138900',
            'company_identity[siret]' => '30613890001294',
            'company_identity[vatExempt]' => '1',
            'company_identity[zip]' => '59650',
            'company_identity[city]' => 'Lille',
        ]));

        self::assertResponseRedirects(self::URL);
    }

    /**
     * @return array<string, string> the company's identifiers, by label
     */
    private function identifiers(): array
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $values = [];
        foreach ($entityManager->getRepository(TaxIdentifier::class)->findBy(['client' => null]) as $identifier) {
            $values[(string) $identifier->getLabel()] = (string) $identifier->getValue();
        }
        ksort($values);

        return $values;
    }
}
