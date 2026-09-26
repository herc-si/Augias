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

namespace Augias\PaymentBundle\Tests\Functional\Api;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\ApiBundle\Test\ApiTestCase;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Test\Factory\PaymentMethodFactory;
use Augias\SettingsBundle\SystemConfig;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('functional')]
final class RecordPaymentTest extends ApiTestCase
{
    protected function getResourceClass(): string
    {
        return Payment::class;
    }

    public function testRecordPayment(): void
    {
        $client = ClientFactory::createOne(['currencyCode' => 'USD']);
        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Pending, 'client' => $client]);

        PaymentMethodFactory::createOne([
            'factoryName' => 'offline',
            'enabled' => true,
            'internal' => false,
        ]);

        $response = self::$client->request('POST', $this->getIriFromResource($invoice) . '/payments', [
            'json' => ['amount' => 1000, 'currency' => 'USD'],
            'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $result = $response->toArray(false);

        self::assertArrayHasKey('id', $result);
        self::assertSame('captured', $result['status']);
        self::assertSame(1000, $result['totalAmount']);
        self::assertSame('USD', $result['currencyCode']);
    }

    /**
     * Recorded outside the books, a private customer's payment would make
     * Augias an uncertified cash register: refused until a regime is chosen.
     */
    public function testAPrivateCustomersPaymentNeedsTheBooks(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::REGIME, '');
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $client = ClientFactory::createOne(['currencyCode' => 'USD', 'isCompany' => false]);
        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Pending, 'client' => $client]);

        PaymentMethodFactory::createOne([
            'factoryName' => 'offline',
            'enabled' => true,
            'internal' => false,
        ]);

        $response = self::$client->request('POST', $this->getIriFromResource($invoice) . '/payments', [
            'json' => ['amount' => 1000, 'currency' => 'USD'],
            'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('art. 286', $response->getContent(false));

        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);

        self::$client->request('POST', $this->getIriFromResource($invoice) . '/payments', [
            'json' => ['amount' => 1000, 'currency' => 'USD'],
            'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testCannotRecordPaymentForDraftInvoice(): void
    {
        $client = ClientFactory::createOne(['currencyCode' => 'USD']);
        $invoice = InvoiceFactory::createOne(['status' => InvoiceStatus::Draft, 'client' => $client]);

        PaymentMethodFactory::createOne([
            'factoryName' => 'offline',
            'enabled' => true,
            'internal' => false,
        ]);

        self::$client->request(
            'POST',
            $this->getIriFromResource($invoice) . '/payments',
            [
                'json' => ['amount' => 1000, 'currency' => 'USD'],
                'headers' => [
                    'content-type' => 'application/ld+json',
                    'accept' => 'application/ld+json',
                ],
            ]
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRecordPaymentForForeignCompanyInvoice(): void
    {
        $otherCompany = CompanyFactory::new()->create();
        self::getContainer()->get(CompanySelector::class)->switchCompany($otherCompany->getId());
        $foreignClient = ClientFactory::createOne(['company' => $otherCompany]);
        $foreignInvoice = InvoiceFactory::createOne(['client' => $foreignClient, 'status' => InvoiceStatus::Pending]);
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        PaymentMethodFactory::createOne([
            'factoryName' => 'offline',
            'enabled' => true,
            'internal' => false,
        ]);

        self::$client->request(
            'POST',
            $this->getIriFromResource($foreignInvoice) . '/payments',
            [
                'json' => ['amount' => 1000, 'currency' => 'USD'],
                'headers' => [
                    'content-type' => 'application/ld+json',
                    'accept' => 'application/ld+json',
                ],
            ]
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
