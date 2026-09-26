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

namespace Augias\BillBundle\Tests\Functional;

use Augias\BillBundle\Manager\BillManager;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * A supplier's e-invoice can be in a currency moneyphp does not know — the
 * Cuban convertible peso, withdrawn in 2021, still valid in Intl. The bill is
 * filed like any other foreign-currency bill, and every page that shows its
 * amount opens instead of answering 500.
 */
#[Group('functional')]
final class BillInUnknownCurrencyTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testTheBillAndTheReceiptShowTheirAmount(): void
    {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        $receipt = new ElectronicInvoiceReceipt()
            ->setProvider('super_pdp')
            ->setExternalReference('cuc-1')
            ->setInvoiceNumber('HAV-1')
            ->setSellerName('Havana Supplies')
            ->setIssueDate(new DateTimeImmutable('2026-09-04'))
            ->setTotalAmount(BigInteger::of(123_456))
            ->setCurrencyCode('CUC');
        $receipt->setCompany($company);
        $this->em->persist($receipt);
        $this->em->flush();

        $bill = self::getContainer()->get(BillManager::class)->createFromReceipt($receipt);
        self::assertSame('CUC', $bill->getCurrencyCode());

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/bills/')
            ->assertSuccessful()
            ->visit('/bills/view/' . $bill->getId())
            ->assertSuccessful()
            ->assertSee('1,234.56')
            ->assertSee('CUC')
            ->visit('/dashboard')
            ->assertSuccessful();
    }
}
