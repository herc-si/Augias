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

namespace Augias\ElectronicInvoicingBundle\Tests\Twig;

use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Twig\ElectronicInvoicingReadinessExtension;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;

#[CoversClass(ElectronicInvoicingReadinessExtension::class)]
final class ElectronicInvoicingReadinessExtensionTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testNothingFilledInIsNotReady(): void
    {
        self::assertSame(
            ['siret' => false, 'vat' => false, 'platform' => false, 'ready' => false],
            $this->readiness(),
        );
    }

    public function testReadyOnceTheIdentifiersAndAPlatformAreThere(): void
    {
        $this->identifier(TaxIdentifierType::SIRET, '73282932000074');
        $this->identifier(TaxIdentifierType::VAT_NUMBER, 'FR44732829320');
        $this->platform();

        self::assertSame(
            ['siret' => true, 'vat' => true, 'platform' => true, 'ready' => true],
            $this->readiness(),
        );
    }

    /**
     * Under the VAT franchise there is no VAT number to ask for: the seller
     * is named by SIRET on its e-invoices.
     */
    public function testTheVatFranchiseAsksForNoVatNumber(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, '1');
        $this->identifier(TaxIdentifierType::SIRET, '73282932000074');
        $this->platform();

        self::assertSame(
            ['siret' => true, 'vat' => null, 'platform' => true, 'ready' => true],
            $this->readiness(),
        );
    }

    public function testTheInvoiceTabLeadsWithThePanelAndFlagsWhatIsMissing(): void
    {
        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');
        $this->identifier(TaxIdentifierType::SIRET, '73282932000074');

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $this->browser()
            ->actingAs($user)
            ->visit('/settings?section=system')
            ->assertSuccessful()
            // Seen from another tab: switched on, but the VAT number and the platform are missing.
            ->assertSeeElement('.nav-link .status-dot.status-warning')
            ->visit('/settings?section=invoice')
            ->assertSuccessful()
            ->assertSeeElement('.einvoicing-panel.einvoicing-panel-warning')
            ->assertSeeElement('.einvoicing-panel-header input[name="settings[electronic_invoicing_enabled]"][checked]')
            ->assertSeeElement('.einvoicing-checklist a[href$="#company-identifiers"]')
            ->assertSeeElement('.einvoicing-checklist a[href$="/providers"]');
    }

    /**
     * @return array{siret: bool, vat: bool|null, platform: bool, ready: bool}
     */
    private function readiness(): array
    {
        return self::getContainer()->get(ElectronicInvoicingReadinessExtension::class)->readiness();
    }

    private function identifier(string $label, string $value): void
    {
        $identifier = new TaxIdentifier();
        $identifier->setCompany($this->company)->setLabel($label)->setValue($value);
        $this->em->persist($identifier);
        $this->em->flush();
    }

    private function platform(): void
    {
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('Test')
            ->setProvider('test_provider')
            ->setSettings([])
            ->setActive(true);
        $this->em->persist($setting);
        $this->em->flush();
    }
}
