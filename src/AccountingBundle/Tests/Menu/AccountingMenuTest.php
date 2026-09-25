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

namespace Augias\AccountingBundle\Tests\Menu;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Menu\AccountingMenu;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\SettingsBundle\SystemConfig;
use Knp\Menu\ItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use SolidWorx\Platform\PlatformBundle\Menu\Provider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_keys;

/**
 * The books a company keeps are the ones its regime asks of it, and the menu
 * lists exactly those.
 *
 * A micro-entrepreneur selling services keeps a livre des recettes and nothing
 * else; a purchase register in their sidebar would invite them to keep a book
 * the law does not ask of them.
 */
#[CoversClass(AccountingMenu::class)]
final class AccountingMenuTest extends KernelTestCase
{
    use DoctrineTestTrait;

    public function testWithoutARegimeTheSectionIsASingleLink(): void
    {
        $this->selectCompany();

        $accounting = $this->accounting();

        self::assertFalse($accounting->hasChildren(), 'Nothing to list until a regime is chosen.');
        self::assertNotNull($accounting->getUri());
    }

    public function testAServiceMicroEntrepriseGetsTheRevenueBookOnly(): void
    {
        $this->selectCompany();
        $this->configureRegime('fr_micro', ActivityNature::ServicesBnc);
        $this->setVatExempt(true);

        self::assertSame(
            [
                'accounting.menu.overview',
                'accounting.book.revenue',
                // Not a statutory book, and offered to everyone: somewhere to
                // file a receipt is useful even where nothing is deductible.
                'accounting.book.expense',
                'accounting.menu.declarations',
            ],
            array_keys($this->accounting()->getChildren()),
        );
    }

    public function testSellingGoodsAddsThePurchaseRegister(): void
    {
        $this->selectCompany();
        $this->configureRegime('fr_micro', ActivityNature::SaleOfGoods);

        self::assertContains('accounting.book.purchase', array_keys($this->accounting()->getChildren()));
    }

    /**
     * VAT on goods falls due when the invoice goes out, and the sales journal
     * is where that is recorded — so it comes with being liable to VAT, micro
     * or not, whatever the main activity.
     */
    public function testChargingVatAddsTheSalesJournal(): void
    {
        $this->selectCompany();
        $this->configureRegime('fr_micro', ActivityNature::ServicesBnc);
        $this->setVatExempt(false);

        self::assertContains('accounting.book.sales', array_keys($this->accounting()->getChildren()));
    }

    private function setVatExempt(bool $exempt): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);

        $config->set(SystemConfig::VAT_EXEMPT_CONFIG_PATH, $exempt ? '1' : '0');
    }

    private function accounting(): ItemInterface
    {
        $provider = self::getContainer()->get(Provider::class);
        self::assertInstanceOf(Provider::class, $provider);

        $accounting = $provider->get('sidebar')->getChild('accounting.menu.main');
        self::assertInstanceOf(ItemInterface::class, $accounting);

        return $accounting;
    }

    private function selectCompany(): void
    {
        $company = CompanyFactory::createOne();

        $selector = self::getContainer()->get(CompanySelector::class);
        self::assertInstanceOf(CompanySelector::class, $selector);
        $selector->switchCompany($company->getId());
    }

    private function configureRegime(string $code, ActivityNature $activity): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);

        $config->set(AccountingSettings::REGIME, $code);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, $activity->value);
    }
}
