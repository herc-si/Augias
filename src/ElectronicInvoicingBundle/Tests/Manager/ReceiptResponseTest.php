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

use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManager;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceReceiptManagerInterface;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function json_encode;

/**
 * Answering a received invoice: through the platform it came from, recorded
 * only once the platform took it, and only once.
 */
#[CoversClass(ElectronicInvoiceReceiptManager::class)]
final class ReceiptResponseTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testAnAcceptanceIsSentAndRecorded(): void
    {
        $receipt = $this->receipt(withProvider: true);
        $this->platformTakesIt();

        $this->manager()->respond($receipt, ReceiptResponse::Accepted);

        self::assertSame(ReceiptResponse::Accepted, $receipt->getResponse());
        self::assertFalse($this->manager()->canRespond($receipt), 'Answered once, not twice.');
    }

    public function testARefusalNeedsAReason(): void
    {
        $receipt = $this->receipt(withProvider: true);

        $this->expectExceptionObject(new RuntimeException('einvoicing.response.reason_required'));

        $this->manager()->respond($receipt, ReceiptResponse::Refused, null, 'Mauvais prix');
    }

    /**
     * A dispute leaves the invoice open: it can still be accepted or refused
     * once settled with the supplier — but not disputed a second time.
     */
    public function testADisputeLeavesTheInvoiceOpenForAnAcceptanceOrARefusal(): void
    {
        $receipt = $this->receipt(withProvider: true);
        $this->platformTakesIt(2);

        $this->manager()->respond($receipt, ReceiptResponse::Disputed, ResponseReason::Quantity, '8 cartons livrés sur 10');

        self::assertSame(ReceiptResponse::Disputed, $receipt->getResponse());
        self::assertTrue($this->manager()->canRespond($receipt));

        try {
            $this->manager()->respond($receipt, ReceiptResponse::Disputed, ResponseReason::UnitPrice);
            self::fail('Disputed once, not twice.');
        } catch (RuntimeException $e) {
            self::assertSame('einvoicing.response.already_answered', $e->getMessage());
        }

        $this->manager()->respond($receipt, ReceiptResponse::Accepted);

        self::assertSame(ReceiptResponse::Accepted, $receipt->getResponse());
        self::assertFalse($this->manager()->canRespond($receipt));
    }

    /**
     * A quantity the buyer disagrees with is a dispute: the platform turns
     * such a code down for a refusal, so it is not sent as one.
     */
    public function testARefusalTakesOnlyAReasonForRefusing(): void
    {
        $receipt = $this->receipt(withProvider: true);

        $this->expectExceptionObject(new RuntimeException('einvoicing.response.reason_not_allowed'));

        $this->manager()->respond($receipt, ReceiptResponse::Refused, ResponseReason::Quantity);
    }

    /**
     * An answer the supplier never got is not an answer: nothing is recorded
     * when the platform refuses it.
     */
    public function testNothingIsRecordedWhenThePlatformRefuses(): void
    {
        $receipt = $this->receipt(withProvider: true);
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient([
            static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token'])),
            static fn (): MockResponse => new MockResponse((string) json_encode(['message' => 'Invalid status']), ['http_code' => 400]),
        ]));

        try {
            $this->manager()->respond($receipt, ReceiptResponse::Accepted);
            self::fail('The refusal should have been reported.');
        } catch (RuntimeException) {
        }

        self::assertNull($receipt->getResponse());
    }

    public function testThereIsNoAnswerWithoutAPlatformInUse(): void
    {
        $receipt = $this->receipt(withProvider: false);

        self::assertFalse($this->manager()->canRespond($receipt));
    }

    private function receipt(bool $withProvider): ElectronicInvoiceReceipt
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        if ($withProvider) {
            $setting = new ElectronicInvoiceProviderSetting();
            $setting->setCompany($this->company)
                ->setName('SUPER PDP')
                ->setProvider('super_pdp')
                ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
                ->setActive(true);
            $entityManager->persist($setting);
        }

        $receipt = new ElectronicInvoiceReceipt();
        $receipt->setCompany($this->company)
            ->setProvider('super_pdp')
            ->setExternalReference('746879')
            ->setInvoiceNumber('TRI-DEBITS')
            ->setSellerName('Tricatel');
        $entityManager->persist($receipt);
        $entityManager->flush();

        return $receipt;
    }

    private function platformTakesIt(int $answers = 1): void
    {
        $responses = [];

        for ($i = 0; $i < $answers; ++$i) {
            $responses[] = static fn (): MockResponse => new MockResponse((string) json_encode(['access_token' => 'a-token']));
            $responses[] = static fn (): MockResponse => new MockResponse((string) json_encode(['id' => 1]));
        }

        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient($responses));
    }

    private function manager(): ElectronicInvoiceReceiptManagerInterface
    {
        return self::getContainer()->get(ElectronicInvoiceReceiptManagerInterface::class);
    }
}
