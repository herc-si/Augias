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

namespace Augias\AccountingBundle\Tests\Attention;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Attention\VatFilingRhythmSource;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Service\VatFilingRhythm;
use Augias\AccountingBundle\Tests\Dashboard\AccountingWidgetTestCase;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;
use function intdiv;

#[CoversClass(VatFilingRhythmSource::class)]
final class VatFilingRhythmSourceTest extends AccountingWidgetTestCase
{
    public function testDoesNotApplyToAnotherRegime(): void
    {
        $this->configureMicroEntreprise();

        self::assertFalse($this->source()->supports());
    }

    /**
     * A company outside the scope of VAT has no filing rhythm to be wrong
     * about, whatever its books say.
     */
    public function testDoesNotApplyToACompanyExemptFromVat(): void
    {
        $this->configureReelNormal();
        $this->config->set(AccountingSettings::VAT_EXEMPT, '1');

        self::assertFalse($this->source()->supports());
    }

    /**
     * Only one direction is reported. Someone already filing monthly is told
     * nothing — "you could file quarterly" would sit on the card every day for
     * companies who are perfectly fine.
     */
    public function testDoesNotApplyToACompanyAlreadyFilingMonthly(): void
    {
        $this->configureReelNormal();
        $this->config->set(AccountingSettings::VAT_PERIODICITY, PeriodType::Month->value);

        self::assertFalse($this->source()->supports());
    }

    public function testAppliesToTheReelNormalFilingQuarterly(): void
    {
        $this->configureReelNormal();

        self::assertTrue($this->source()->supports());
    }

    public function testSaysNothingWhileTheToleranceHolds(): void
    {
        $this->configureReelNormal();
        $this->saleInsideTheWindow(VatFilingRhythm::QUARTERLY_CEILING - 1);

        self::assertFalse($this->source()->hasItems());
    }

    public function testReportsACompanyThatHasOutgrownQuarterlyFiling(): void
    {
        $this->configureReelNormal();
        $this->saleInsideTheWindow(VatFilingRhythm::QUARTERLY_CEILING);

        self::assertTrue($this->source()->hasItems());
    }

    /**
     * The window is named on screen, so it has to come out of the source rather
     * than be guessed at by the template.
     */
    public function testCarriesTheFiguresAndTheWindowTheTemplateNames(): void
    {
        $this->configureReelNormal();
        $this->saleInsideTheWindow(450_000);

        $data = $this->source()->getData();
        $expected = self::getContainer()->get(VatFilingRhythm::class)
            ->trailingYear(new DateTimeImmutable('today'));

        self::assertSame('450000', (string) $data['tax']);
        self::assertSame((string) VatFilingRhythm::QUARTERLY_CEILING, (string) $data['ceiling']);
        self::assertSame('EUR', $data['currencyCode']);
        self::assertEquals($expected['from'], $data['from']);
        self::assertEquals($expected['to'], $data['to']);
    }

    /**
     * Rendered from fixed figures rather than from the live source: the window
     * moves on the first day of every quarter, and a snapshot holding the month
     * names it computes would fail four times a year for no reason. What the
     * data it renders looks like is the test above.
     */
    public function testRendersAsASectionOfTheAttentionCard(): void
    {
        $this->configureReelNormal();

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $this->assertMatchesHtmlSnapshot($this->normalise($twig->render($this->source()->getTemplate(), [
            'tax' => BigInteger::of(527_400),
            'ceiling' => BigInteger::of(VatFilingRhythm::QUARTERLY_CEILING),
            'currencyCode' => 'EUR',
            'from' => new DateTimeImmutable('2025-07-01'),
            'to' => new DateTimeImmutable('2026-06-30'),
        ])));
    }

    /**
     * A sale in the quarter before the current one, which is inside the
     * trailing four whatever day the suite runs on.
     */
    private function saleInsideTheWindow(int $tax): void
    {
        $today = new DateTimeImmutable('today');
        $month = intdiv((int) $today->format('n') - 1, 3) * 3 + 1;
        $on = $today->setDate((int) $today->format('Y'), $month, 1)->setTime(0, 0)->modify('-1 month');

        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate($on)
            ->setLabel('Operation')
            ->setCounterpartyName('Someone')
            ->setAmount(BigInteger::of($tax * 6))
            ->setCurrencyCode('EUR');

        $entry->setCompany($this->companyReference());
        $entry->setActivityNature(ActivityNature::ServicesBnc)
            ->setTax(
                BigInteger::of($tax * 5),
                BigInteger::of($tax),
                [['rate' => '20.0000', 'category' => 'Standard', 'base' => (string) ($tax * 5), 'tax' => (string) $tax]],
            );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function source(): VatFilingRhythmSource
    {
        $source = self::getContainer()->get(VatFilingRhythmSource::class);
        self::assertInstanceOf(VatFilingRhythmSource::class, $source);

        return $source;
    }
}
