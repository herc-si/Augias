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

namespace Augias\PaymentBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\PaymentBundle\Test\Factory\PaymentMethodFactory;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * A user recording a private customer's payment is sent to choose a regime
 * first; the customer paying online is not turned away.
 */
#[Group('functional')]
final class PrivateCustomerPaymentTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testRecordingAPrivateCustomersPaymentAsksForTheBooks(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(AccountingSettings::REGIME, '');
        $config->set(AccountingSettings::VAT_EXEMPT, '0');

        $client = ClientFactory::createOne(['company' => $this->company, 'isCompany' => false, 'currencyCode' => 'EUR']);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending]);
        PaymentMethodFactory::createOne(['factoryName' => 'offline', 'enabled' => true, 'internal' => false]);

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/payments/create/' . $invoice->getUuid())
            ->assertSuccessful()
            ->assertOn('/invoices/view/' . $invoice->getId())
            ->assertSee('art. 286');

        // The customer, from the link on their invoice, still reaches the form.
        $this->browser()
            ->visit('/payments/create/' . $invoice->getUuid())
            ->assertSuccessful()
            ->assertNotSee('art. 286');
    }
}
