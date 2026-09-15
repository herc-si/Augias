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

namespace Augias\ElectronicInvoicingBundle\Tests\Dashboard;

use Augias\DashboardBundle\Widgets\WidgetInterface;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use function preg_replace;
use function trim;

/**
 * Shared ground for the two cards this bundle contributes: an installed
 * application whose electronic invoicing can be switched on and off.
 */
abstract class EInvoicingWidgetTestCase extends KernelTestCase
{
    use EnsureApplicationInstalled;
    use MatchesSnapshots;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    protected function activateTestProvider(): void
    {
        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)
            ->setName('Test Provider')
            ->setProvider('test_provider')
            ->setSettings([])
            ->setActive(true);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    protected function render(WidgetInterface $widget): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render($widget->getTemplate(), $widget->getData());

        // A ULID is new on every run, and a submission is stamped with the time
        // it was created — so both would make these snapshots fail for reasons
        // that have nothing to do with the markup, the second one only once the
        // date rolled over.
        $html = (string) preg_replace('#[0-9A-HJKMNP-TV-Z]{26}#', '01JBYEQCR7DJ2YW4EXP6FYJZCR', $html);
        // Either order: ICU puts the month first in English and the day first
        // in French, and this has to keep matching whichever locale the suite
        // runs in — a normaliser that silently stops normalising leaves a real
        // date in the snapshot, which passes today and fails when the month
        // turns over.
        $months = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

        $html = (string) preg_replace(
            '#\b(?:(?:' . $months . ')\.? \d{1,2}|\d{1,2} (?:' . $months . ')\.?)\b#',
            'MONTH DAY',
            $html,
        );

        return trim($html);
    }
}
