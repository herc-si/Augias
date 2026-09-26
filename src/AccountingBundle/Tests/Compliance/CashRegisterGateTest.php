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

namespace Augias\AccountingBundle\Tests\Compliance;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Attention\BooksRequiredSource;
use Augias\AccountingBundle\Compliance\CashRegisterGate;
use Augias\AccountingBundle\Tests\Dashboard\AccountingWidgetTestCase;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Contracts\CashRegisterGateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Recording a private customer's payment outside the books would make Augias
 * an uncertified cash register (CGI, art. 286, I, 3° bis): a VAT-registered
 * company keeps its books before it may.
 */
#[CoversClass(CashRegisterGate::class)]
#[CoversClass(BooksRequiredSource::class)]
final class CashRegisterGateTest extends AccountingWidgetTestCase
{
    public function testAVatRegisteredCompanyWithoutBooksMustKeepThem(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '0');

        self::assertTrue($this->gate()->requiresBooks());
        self::assertTrue($this->gate()->refusesPaymentFrom($this->companyReference(), $this->customer(private: true)));
        self::assertFalse($this->gate()->refusesPaymentFrom($this->companyReference(), $this->customer(private: false)), 'A business pays against an invoice: out of scope.');
    }

    public function testKeepingTheBooksLiftsIt(): void
    {
        $this->configureReelNormal();

        self::assertFalse($this->gate()->requiresBooks());
        self::assertFalse($this->gate()->refusesPaymentFrom($this->companyReference(), $this->customer(private: true)));
    }

    public function testFranchiseEnBaseIsOutsideIt(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '1');

        self::assertFalse($this->gate()->requiresBooks());
    }

    public function testAPrivateCustomerIsRefusedUntilTheBooksAreKept(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '0');

        $validator = self::getContainer()->get(ValidatorInterface::class);
        $private = $this->customer(private: true);

        $violations = $validator->validate($private);
        self::assertCount(1, $violations);
        self::assertSame('name', $violations[0]->getPropertyPath());
        self::assertStringContainsString('art. 286', (string) $violations[0]->getMessage());

        self::assertCount(0, $validator->validate($this->customer(private: false)));

        $this->configureReelNormal();
        self::assertCount(0, $validator->validate($private));
    }

    public function testTheDashboardSaysSoOnceThereIsAPrivateCustomer(): void
    {
        $this->config->set(AccountingSettings::REGIME, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '0');

        $source = self::getContainer()->get(BooksRequiredSource::class);
        self::assertTrue($source->supports());
        self::assertFalse($source->hasItems(), 'Businesses only: nothing to say.');

        ClientFactory::createOne(['company' => $this->company, 'isCompany' => false]);

        self::assertTrue($source->hasItems());
        self::assertSame(['privateCustomers' => 1], $source->getData());
    }

    private function customer(bool $private): Client
    {
        $client = new Client();
        $client->setName($private ? 'Jean Dupont' : 'Acme')->setIsCompany(! $private)->setCurrencyCode('EUR');

        return $client;
    }

    private function gate(): CashRegisterGateInterface
    {
        return self::getContainer()->get(CashRegisterGateInterface::class);
    }
}
