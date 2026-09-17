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

namespace Augias\AccountingBundle\Tests\Dashboard;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Regime\Fr\ReelNormalRegime;
use Augias\CoreBundle\Entity\Company;
use Augias\DashboardBundle\Widgets\WidgetInterface;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function preg_replace;
use function trim;

/**
 * Shared ground for the dashboard cards this bundle contributes: an installed
 * application, a company, and a regime that can be switched on and off.
 */
abstract class AccountingWidgetTestCase extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use MatchesSnapshots;

    protected EntityManagerInterface $entityManager;

    protected SystemConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $config = self::getContainer()->get(SystemConfig::class);
        self::assertInstanceOf(SystemConfig::class, $config);
        $this->config = $config;

        // The books are kept in the company's own currency, so the fixtures have
        // to agree with it.
        $this->config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
    }

    protected function configureMicroEntreprise(): void
    {
        $this->config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $this->config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $this->config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    /**
     * The réel normal, filing VAT on the books' own quarterly rhythm — the
     * setting the filing-rhythm section has something to say about.
     */
    protected function configureReelNormal(): void
    {
        $this->config->set(AccountingSettings::REGIME, ReelNormalRegime::CODE);
        $this->config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $this->config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
        $this->config->set(AccountingSettings::VAT_PERIODICITY, '');
        $this->config->set(AccountingSettings::VAT_EXEMPT, '0');
    }

    protected function companyReference(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    protected function render(WidgetInterface $widget): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $this->normalise($twig->render($widget->getTemplate(), $widget->getData()));
    }

    /**
     * Take the clock and the id generator out of the snapshot.
     *
     * Both would otherwise make these tests fail for reasons that have nothing
     * to do with the markup: a ULID is new on every run, and a card that names
     * the current year would start failing on 1 January. What the snapshots are
     * for is the structure and the classes around those values.
     */
    protected function normalise(string $html): string
    {
        $html = preg_replace('#[0-9A-HJKMNP-TV-Z]{26}#', '01JBYEQCR7DJ2YW4EXP6FYJZCR', $html);
        $html = preg_replace('#\b20\d{2}\b#', 'YEAR', (string) $html);

        return trim((string) $html);
    }
}
