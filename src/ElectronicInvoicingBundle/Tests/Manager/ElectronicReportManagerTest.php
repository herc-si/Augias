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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicReportManager;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_encode;
use function str_contains;

#[CoversClass(ElectronicReportManager::class)]
final class ElectronicReportManagerTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private int $transactionsPosted = 0;

    private bool $platformUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');

        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth2/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'a-token']));
            }

            if (! $this->platformUp) {
                return new MockResponse('', ['http_code' => 503]);
            }

            ++$this->transactionsPosted;

            return new MockResponse((string) json_encode(['data' => [['id' => 41]]]));
        })));

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)->setName('SUPER PDP')->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])->setActive(true);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($setting);
        $entityManager->flush();
    }

    public function testASaleToAPrivateIndividualIsReportedOnce(): void
    {
        $this->invoice(private: true, date: 'today');

        self::assertSame(['reported' => 1, 'failed' => 0], $this->manager()->reportPending($this->company));
        self::assertSame(['reported' => 0, 'failed' => 0], $this->manager()->reportPending($this->company), 'Not twice.');
        self::assertSame(1, $this->transactionsPosted);
    }

    /**
     * A business is invoiced electronically, and a sale from before
     * reporting started is not the platform's to receive.
     */
    public function testOnlyPrivateSalesFromTheStartOnAreReported(): void
    {
        $this->invoice(private: false, date: 'today');
        $this->invoice(private: true, date: '2020-01-01');

        self::assertSame(['reported' => 0, 'failed' => 0], $this->manager()->reportPending($this->company));
    }

    public function testAFailedReportIsTriedAgain(): void
    {
        $this->invoice(private: true, date: 'today');

        $this->platformUp = false;
        self::assertSame(['reported' => 0, 'failed' => 1], $this->manager()->reportPending($this->company));

        $this->platformUp = true;
        self::assertSame(['reported' => 1, 'failed' => 0], $this->manager()->reportPending($this->company));

        $reports = self::getContainer()->get('doctrine')->getRepository(ElectronicReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertTrue($reports[0]->isSuccess());
        self::assertSame('41', $reports[0]->getExternalReference());
    }

    private function invoice(bool $private, string $date): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        if (! $private) {
            $client->setSiret('12345678900012');
        }

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('B2C-' . $date . ($private ? '-p' : '-b'));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceDate(new DateTimeImmutable($date));
        $invoice->addLine(new Line()->setDescription('Pizza')->setPrice(1200)->setQty(1)->updateTotal());

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();
    }

    private function manager(): ElectronicReportManager
    {
        return self::getContainer()->get(ElectronicReportManager::class);
    }
}
