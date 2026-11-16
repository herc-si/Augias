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

namespace Augias\InvoiceBundle\Tests\Twig\Extension;

use const JSON_THROW_ON_ERROR;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Twig\Extension\SellerIdentityExtension;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function json_encode;

/**
 * An invoice has to name who issues it. The forms say so while the company's
 * address or identifier is missing, and stay quiet once both are there.
 */
#[CoversClass(SellerIdentityExtension::class)]
final class SellerIdentityExtensionTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testNothingFilledInMissesBoth(): void
    {
        self::assertSame(['address', 'identifier'], $this->gaps());
    }

    public function testACountryAloneIsNotAnAddress(): void
    {
        $this->address(['country' => 'FR']);

        self::assertContains('address', $this->gaps());
    }

    public function testAnAddressAndAnIdentifierLeaveNothingToSay(): void
    {
        $this->address(['street1' => '1 rue de la Paix', 'city' => 'Paris', 'zip' => '75002', 'country' => 'FR']);
        self::assertSame(['identifier'], $this->gaps());

        $this->identifier('73282932000074');
        self::assertSame([], $this->gaps());
    }

    public function testAnEmptyIdentifierDoesNotCount(): void
    {
        $this->identifier('   ');

        self::assertContains('identifier', $this->gaps());
    }

    public function testTheInvoiceAndQuoteFormsWarnUntilTheIdentityIsComplete(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company]);
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $clientId = $client->getId()->toBase32();
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/invoices/create/' . $clientId)
            ->assertSuccessful()
            ->assertSeeElement('.seller-identity-warning a[href*="section=system"]')
            ->visit('/quotes/create/' . $clientId)
            ->assertSuccessful()
            ->assertSeeElement('.seller-identity-warning');

        $this->address(['street1' => '1 rue de la Paix', 'city' => 'Paris']);
        $this->identifier('73282932000074');

        $this->browser()
            ->actingAs($user)
            ->visit('/invoices/create/' . $clientId)
            ->assertSuccessful()
            ->assertNotSeeElement('.seller-identity-warning')
            ->visit('/quotes/create/' . $clientId)
            ->assertSuccessful()
            ->assertNotSeeElement('.seller-identity-warning');
    }

    /**
     * @return list<string>
     */
    private function gaps(): array
    {
        return self::getContainer()->get(SellerIdentityExtension::class)->gaps();
    }

    /**
     * @param array<string, string> $address
     */
    private function address(array $address): void
    {
        self::getContainer()->get(SystemConfig::class)->set('system/company/contact_details/address', json_encode($address, JSON_THROW_ON_ERROR));
    }

    private function identifier(string $value): void
    {
        $identifier = new TaxIdentifier();
        // A reference: the browser test clears the entity manager, which detaches the company.
        $identifier->setCompany($this->em->getReference(Company::class, $this->company->getId()))->setLabel(TaxIdentifierType::SIRET)->setValue($value);
        $this->em->persist($identifier);
        $this->em->flush();
    }
}
