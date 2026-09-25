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

namespace Augias\ElectronicInvoicingBundle\Tests\Provider;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Enum\AccountVerification;
use Augias\ElectronicInvoicingBundle\Enum\ElectronicInvoiceProcessingStatus;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdpProvider;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\LineTax;
use Augias\TaxBundle\Test\Factory\TaxIdentifierFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function json_encode;

#[CoversClass(SuperPdpProvider::class)]
final class SuperPdpProviderTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testSendUploadsTheInvoiceAndReturnsTheSuperPdpId(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['id' => 4242, 'events' => []])),
        ]));

        $invoice = $this->createEligibleInvoice();

        $result = self::getContainer()->get(SuperPdpProvider::class)->send($invoice, [
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        self::assertTrue($result->success);
        self::assertSame('4242', $result->externalReference);
    }

    public function testSendReturnsAFailureWhenCredentialsAreMissing(): void
    {
        $invoice = $this->createEligibleInvoice();

        $result = self::getContainer()->get(SuperPdpProvider::class)->send($invoice, []);

        self::assertFalse($result->success);
        self::assertSame('einvoicing.provider.super_pdp.missing_credentials', $result->message);
    }

    public function testSendReturnsAFailureWhenTheApiRejectsTheDocument(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse(
                (string) json_encode(['code' => 1, 'http_status_code' => 400, 'message' => 'Invalid document']),
                ['http_code' => 400],
            ),
        ]));

        $invoice = $this->createEligibleInvoice();

        $result = self::getContainer()->get(SuperPdpProvider::class)->send($invoice, [
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        self::assertFalse($result->success);
        self::assertStringContainsString('Invalid document', (string) $result->message);
    }

    /**
     * An unverified account is refused everywhere with a bare 403. The user is
     * told why, not shown the platform's message.
     */
    public function testSendExplainsThatTheAccountIsStillUnderReview(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['message' => 'Forbidden']), ['http_code' => 403]),
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['created_at' => '2026-09-25T09:58:15Z', 'company_verification_status' => 'needs_review'])),
        ]));

        $result = self::getContainer()->get(SuperPdpProvider::class)->send($this->createEligibleInvoice(), [
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        self::assertFalse($result->success);
        self::assertSame('einvoicing.provider.super_pdp.needs_review', $result->message);
    }

    /**
     * The shape the sandbox answered with on 25/09/2026, for an account that is
     * verified but whose VAT settings were never filled in at SUPER PDP.
     */
    public function testCheckAccountReportsTheCompanyAndWhereItsVatSettingsDisagree(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['created_at' => '2026-09-25T09:58:15Z', 'company_verification_status' => 'verified'])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'id' => 92569,
                'env' => 'sandbox',
                'number_scheme' => 'sandbox',
                'number' => '000000002',
                'formal_name' => 'Burger Queen',
                'vat_regime' => '',
                'has_vat_on_debits' => false,
            ])),
        ]));

        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '0');
        $config->set(SystemConfig::VAT_ON_DEBITS_CONFIG_PATH, '1');

        $status = self::getContainer()->get(SuperPdpProvider::class)->checkAccount(['client_id' => 'id', 'client_secret' => 'secret']);

        self::assertSame(AccountVerification::Verified, $status->verification);
        self::assertSame('Burger Queen', $status->companyName);
        self::assertTrue($status->isSandbox());
        self::assertSame([
            'einvoicing.account.warning.vat_on_debits',
            'einvoicing.account.warning.vat_regime_missing',
        ], $status->warnings);
    }

    /**
     * Until the identity is verified nothing else answers, so nothing else is
     * asked.
     */
    public function testCheckAccountStopsAtAnUnverifiedIdentity(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['created_at' => '2026-09-25T09:58:15Z', 'company_verification_status' => 'failed'])),
        ]));

        $status = self::getContainer()->get(SuperPdpProvider::class)->checkAccount(['client_id' => 'id', 'client_secret' => 'secret']);

        self::assertSame(AccountVerification::Failed, $status->verification);
        self::assertNull($status->companyName);
    }

    public function testCheckAccountSaysWhenTheCredentialsAreRefused(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse('', ['http_code' => 401]),
        ]));

        $status = self::getContainer()->get(SuperPdpProvider::class)->checkAccount(['client_id' => 'id', 'client_secret' => 'wrong']);

        self::assertSame(AccountVerification::Unknown, $status->verification);
        self::assertSame('einvoicing.provider.super_pdp.bad_credentials', $status->error);
    }

    public function testResolveProcessingStatusReturnsRejectedWhenTheInitialSendFailed(): void
    {
        $submission = new ElectronicInvoiceSubmission()->setSuccess(false);

        $status = self::getContainer()->get(SuperPdpProvider::class)->resolveProcessingStatus($submission);

        self::assertSame(ElectronicInvoiceProcessingStatus::Rejected, $status);
    }

    public function testResolveProcessingStatusReturnsPendingWhenNotYetPolled(): void
    {
        $submission = new ElectronicInvoiceSubmission()->setSuccess(true);

        $status = self::getContainer()->get(SuperPdpProvider::class)->resolveProcessingStatus($submission);

        self::assertSame(ElectronicInvoiceProcessingStatus::Pending, $status);
    }

    /**
     * @return iterable<string, array{string, ElectronicInvoiceProcessingStatus}>
     */
    public static function statusCodeProvider(): iterable
    {
        yield 'fr:205 Accepted' => ['fr:205', ElectronicInvoiceProcessingStatus::Accepted];
        yield 'fr:206 Partly accepted' => ['fr:206', ElectronicInvoiceProcessingStatus::Accepted];
        yield 'fr:209 Completed' => ['fr:209', ElectronicInvoiceProcessingStatus::Accepted];
        yield 'fr:210 Refused' => ['fr:210', ElectronicInvoiceProcessingStatus::Rejected];
        yield 'fr:213 Rejected' => ['fr:213', ElectronicInvoiceProcessingStatus::Rejected];
        yield 'fr:501 Inadmissible' => ['fr:501', ElectronicInvoiceProcessingStatus::Rejected];
        yield 'api:rejected' => ['api:rejected', ElectronicInvoiceProcessingStatus::Rejected];
        yield 'api:invalid' => ['api:invalid', ElectronicInvoiceProcessingStatus::Rejected];
        yield 'fr:201 Sent (non-terminal)' => ['fr:201', ElectronicInvoiceProcessingStatus::Pending];
        yield 'fr:208 On hold (non-terminal)' => ['fr:208', ElectronicInvoiceProcessingStatus::Pending];
    }

    #[DataProvider('statusCodeProvider')]
    public function testResolveProcessingStatusClassifiesKnownStatusCodes(string $statusCode, ElectronicInvoiceProcessingStatus $expected): void
    {
        $submission = new ElectronicInvoiceSubmission()->setSuccess(true)->setStatusCode($statusCode);

        $status = self::getContainer()->get(SuperPdpProvider::class)->resolveProcessingStatus($submission);

        self::assertSame($expected, $status);
    }

    public function testFetchIncomingMapsListedInvoicesToReceivedData(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse((string) json_encode([
                'count' => 1,
                'has_after' => false,
                'has_before' => false,
                'data' => [
                    [
                        'id' => 555,
                        'company_id' => 1,
                        'created_at' => '2026-01-01T10:00:00Z',
                        'direction' => 'in',
                        'events' => [
                            ['status_code' => 'fr:200', 'created_at' => '2026-01-01T10:00:00Z'],
                            ['status_code' => 'fr:205', 'created_at' => '2026-01-02T10:00:00Z'],
                        ],
                        'en_invoice' => [
                            'number' => 'SUP-0042',
                            'issue_date' => '2026-01-01',
                            'currency_code' => 'EUR',
                            'seller' => [
                                'name' => 'Acme Supplies',
                                'identifiers' => [
                                    ['scheme' => '0002', 'value' => '11122233300045'],
                                ],
                            ],
                            'totals' => [
                                'amount_due_for_payment' => '199.90',
                            ],
                        ],
                    ],
                ],
            ])),
        ]));

        $results = self::getContainer()->get(SuperPdpProvider::class)->fetchIncoming(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            null,
        );

        self::assertCount(1, $results);
        $data = $results[0];
        self::assertSame('555', $data->externalReference);
        self::assertSame('SUP-0042', $data->invoiceNumber);
        self::assertSame('Acme Supplies', $data->sellerName);
        self::assertSame('11122233300045', $data->sellerIdentifier);
        self::assertSame('2026-01-01', $data->issueDate?->format('Y-m-d'));
        self::assertNotNull($data->totalAmount);
        self::assertSame('19990', (string) $data->totalAmount);
        self::assertSame('EUR', $data->currencyCode);
        self::assertSame('fr:205', $data->statusCode);
    }

    public function testFetchIncomingReturnsEmptyWhenCredentialsAreMissing(): void
    {
        $results = self::getContainer()->get(SuperPdpProvider::class)->fetchIncoming([], null);

        self::assertSame([], $results);
    }

    /**
     * Regression test for a real SUPER PDP response observed in production
     * testing: two events created microseconds apart can carry timestamp
     * strings with a *different number of fractional-second digits* (here
     * ".43454Z" vs ".434541Z") — comparing those as plain strings sorts the
     * shorter one after the longer one regardless of which actually happened
     * first, so latestStatusCode() must order by `id` instead.
     */
    public function testLatestStatusCodeOrdersByIdNotByAmbiguousTimestampStrings(): void
    {
        $events = [
            ['id' => 1290543, 'status_code' => 'api:uploaded', 'created_at' => '2026-09-04T10:00:39.834002Z'],
            ['id' => 1290544, 'status_code' => 'fr:200', 'created_at' => '2026-09-04T10:00:40.43454Z'],
            ['id' => 1290545, 'status_code' => 'fr:201', 'created_at' => '2026-09-04T10:00:40.434541Z'],
        ];

        self::assertSame('fr:201', SuperPdpProvider::latestStatusCode($events));
    }

    public function testLatestStatusCodeReturnsNullForEmptyOrInvalidInput(): void
    {
        self::assertNull(SuperPdpProvider::latestStatusCode(null));
        self::assertNull(SuperPdpProvider::latestStatusCode([]));
        self::assertNull(SuperPdpProvider::latestStatusCode('not an array'));
    }

    public function testDownloadIncomingDocumentReturnsTheRawContentAndMimeType(): void
    {
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token', 'expires_in' => 3600])),
            static fn (): MockResponse => new MockResponse('%PDF-1.7 fake content', ['response_headers' => ['content-type' => 'application/pdf']]),
        ]));

        $document = self::getContainer()->get(SuperPdpProvider::class)->downloadIncomingDocument(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            '555',
        );

        self::assertSame('%PDF-1.7 fake content', $document->content);
        self::assertSame('application/pdf', $document->mimeType);
        self::assertSame('pdf', $document->fileExtension);
    }

    private function createEligibleInvoice(): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        TaxIdentifierFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'label' => 'SIRET',
            'value' => '22222222200022',
        ]);

        $invoice = new Invoice();
        $invoice->setCompany($this->company);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-0001');
        $invoice->setStatus(InvoiceStatus::Draft);

        $line = new Line();
        $line->setDescription('Consulting services');
        $line->setPrice(10000);
        $line->setQty(1);
        $line->updateTotal();

        $lineTax = new LineTax();
        $lineTax->setNameSnapshot('VAT');
        $lineTax->setRateSnapshot('20.0000');
        $line->addTax($lineTax);

        $invoice->addLine($line);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        return $invoice;
    }
}
