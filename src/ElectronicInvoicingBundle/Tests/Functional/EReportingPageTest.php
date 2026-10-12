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

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Action\EReporting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicDeclaration;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicReport;
use Augias\ElectronicInvoicingBundle\Enum\ReportKind;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_encode;
use function str_contains;

/**
 * The page that shows where e-reporting stands, and the line on an invoice
 * to a private individual.
 */
#[CoversClass(EReporting::class)]
#[Group('functional')]
final class EReportingPageTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private const string PREVIEW = '<Report><TransactionsReport><ReportPeriod><StartDate>20261011</StartDate><EndDate>20261020</EndDate></ReportPeriod>'
        . '<Transactions><Date>20261012</Date><CategoryCode>TPS1</CategoryCode><TaxExclusiveAmount>1234.50</TaxExclusiveAmount><TaxTotal>246.90</TaxTotal></Transactions>'
        . '</TransactionsReport></Report>';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Company $managed;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // The company installed before the kernel restarted belongs to another manager.
        $company = $entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->managed = $company;
    }

    public function testWithoutAPlatformItSaysWhereToConnectOne(): void
    {
        $crawler = $this->client->request('GET', '/electronic-invoicing/ereporting');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No platform connected', $crawler->filter('body')->text());
    }

    public function testShowsTheNextDeclarationThoseSentAndWhatWasFiled(): void
    {
        $this->connectSuperPdp();
        $invoice = $this->privateSale();

        $declaration = new ElectronicDeclaration('super_pdp', '7', 'transaction', new DateTimeImmutable('2026-09-21'), new DateTimeImmutable('2026-09-30'));
        $declaration->setCompany($this->managed);
        $declaration->updateStatus('ppf:ereporting-rejected', new DateTimeImmutable('2026-10-11 10:00'));
        $this->entityManager->persist($declaration);

        $report = new ElectronicReport(ReportKind::Transaction, $invoice->getId(), 'super_pdp');
        $report->setCompany($this->managed);
        $report->succeeded('276778');
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/electronic-invoicing/ereporting');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('from 11/10/2026 to 20/10/2026', $text);
        self::assertStringContainsString('1,234.50', $text);
        self::assertStringContainsString('Rejected', $text);
        self::assertStringContainsString('B2C-PAGE', $text);
        self::assertStringContainsString('Filed', $text);
    }

    /**
     * An invoice to a private individual says it was reported, and when.
     */
    public function testAnInvoiceToAPrivateIndividualSaysItWasReported(): void
    {
        $this->connectSuperPdp();
        $invoice = $this->privateSale();

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Will be reported on the next run', $crawler->filter('#ereporting-card')->text());

        $report = new ElectronicReport(ReportKind::Transaction, $invoice->getId(), 'super_pdp');
        $report->setCompany($this->managed);
        $report->succeeded('276778');
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/invoices/view/' . $invoice->getId());
        self::assertStringContainsString('Reported on', $crawler->filter('#ereporting-card')->text());
    }

    private function connectSuperPdp(): void
    {
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/oauth2/token')) {
                return new MockResponse((string) json_encode(['access_token' => 'a-token']));
            }

            if (str_contains($url, 'kind=transaction')) {
                return new MockResponse(self::PREVIEW);
            }

            return new MockResponse('', ['http_code' => 204]);
        })));

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->managed)->setName('SUPER PDP')->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])->setActive(true);
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    private function privateSale(): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->managed, 'currencyCode' => 'EUR', 'isCompany' => false]);

        $invoice = new Invoice();
        $invoice->setCompany($this->managed);
        $invoice->setClient($client);
        $invoice->setInvoiceId('B2C-PAGE');
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceDate(new DateTimeImmutable('today'));
        $invoice->addLine(new Line()->setDescription('Pizza')->setPrice(1200)->setQty(1)->updateTotal());
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }
}
