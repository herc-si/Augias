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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\AccountingBundle\AccountingSettings;
use Augias\AccountingBundle\Action\Book;
use Augias\AccountingBundle\Action\ClosePeriod;
use Augias\AccountingBundle\Action\Entry\Add;
use Augias\AccountingBundle\Action\Entry\Delete;
use Augias\AccountingBundle\Action\Entry\Edit;
use Augias\AccountingBundle\Action\Index;
use Augias\AccountingBundle\Entity\AccountingPeriod;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\AccountingPeriodManager;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The accounting screens, driven through real requests: what the pages render
 * is most of what this module is, and a template that no longer matches its
 * action fails silently everywhere else.
 */
#[CoversClass(Index::class)]
#[CoversClass(Book::class)]
#[CoversClass(Add::class)]
#[CoversClass(Edit::class)]
#[CoversClass(Delete::class)]
#[CoversClass(ClosePeriod::class)]
#[Group('functional')]
final class AccountingPagesTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        // The identity map is read back between requests, so the kernel has to
        // survive them rather than being rebooted with a fresh one each time.
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
    }

    public function testTheHomePagePromptsForSetupUntilARegimeIsChosen(): void
    {
        $crawler = $this->client->request('GET', '/accounting/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'not set up yet',
            $crawler->filter('.card')->text(),
        );
    }

    public function testTheHomePageShowsTheYearsTurnoverOnceThereIsSome(): void
    {
        $this->configureRegime();
        $this->entry(120_000);

        $crawler = $this->client->request('GET', '/accounting/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('1,200.00', $crawler->filter('body')->text());
    }

    /**
     * `.card-header` is a flex row, so a title and a subtitle handed to it as
     * two separate children end up side by side with nothing between them.
     * They have to share one wrapper to stack.
     */
    public function testACardSubtitleSitsUnderItsTitleRatherThanBesideIt(): void
    {
        $this->configureRegime();
        $this->entry(120_000);

        $crawler = $this->client->request('GET', '/accounting/');

        self::assertGreaterThan(
            0,
            $crawler->filter('.card-header > div > .card-title + .card-subtitle')->count(),
            'A card subtitle must be wrapped with its title, not dropped straight into the flex header.',
        );
    }

    public function testTheRevenueBookListsItsEntries(): void
    {
        $this->configureRegime();
        $this->entry(120_000);

        $crawler = $this->client->request('GET', '/accounting/book/revenue');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Consulting work', $crawler->filter('body')->text());
    }

    /**
     * A services-only micro-entrepreneur keeps no purchase register, so the
     * page does not exist for them rather than existing and being empty.
     */
    public function testTheBookAServicesBusinessDoesNotKeepIsNotThere(): void
    {
        $this->configureRegime();

        $this->client->request('GET', '/accounting/book/purchase');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAnEntryCanBeAddedByHand(): void
    {
        $this->configureRegime();

        // Driven through the rendered form rather than a hand-built POST: the
        // token and the field names then come from the page itself, so a form
        // that no longer matches its action fails here rather than in the app.
        $crawler = $this->client->request('GET', '/accounting/book/revenue/entry/add');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->submit($crawler->selectButton('Save')->form([
            'ledger_entry[entryDate]' => '2026-02-10',
            'ledger_entry[label]' => 'Cash payment',
            'ledger_entry[counterpartyName]' => 'A passer-by',
            'ledger_entry[documentReference]' => 'REC-001',
            'ledger_entry[amount]' => '250.00',
            'ledger_entry[settlementMethod]' => 'cash',
            'ledger_entry[activityNature]' => 'services_bnc',
        ]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/accounting/book/revenue', $this->client->getResponse()->headers->get('Location'));

        $entries = $this->entries();

        self::assertCount(1, $entries);
        self::assertSame('25000', (string) $entries[0]->getAmount());
        self::assertSame('Cash payment', $entries[0]->getLabel());
        // Filed into the period its date falls in, which this entry brought
        // into existence.
        self::assertSame('2026-Q1', $entries[0]->getPeriod()?->getLabel());
    }

    /**
     * The home page shows the period today falls in, and that one is by
     * definition still running — money received tomorrow still belongs in it.
     * Offering to seal it would offer a button the manager refuses.
     */
    public function testTheHomePageDoesNotOfferToSealTheRunningPeriod(): void
    {
        $this->configureRegime();
        $this->entry(120_000, 'today');

        $crawler = $this->client->request('GET', '/accounting/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('form[action*="/close"]'));
    }

    /**
     * A period that has ended is sealed from its declaration — the page that is
     * reached per period, and the one that explains why the figures are not
     * final until it is.
     */
    public function testAnEndedPeriodIsClosedFromItsDeclaration(): void
    {
        $this->configureRegime();
        // Q1 2026, long over by the time anything runs this.
        $this->entry(120_000);

        $period = $this->entries()[0]->getPeriod();
        self::assertInstanceOf(AccountingPeriod::class, $period);

        $crawler = $this->client->request('GET', '/accounting/declarations/' . $period->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->submit($crawler->filter('form[action*="/close"]')->form());

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            '/accounting/declarations/' . $period->getId(),
            $this->client->getResponse()->headers->get('Location'),
        );

        $entry = $this->entries()[0];

        self::assertTrue($entry->isLocked());
        self::assertSame(1, $entry->getSequenceNumber());
    }

    /**
     * The one place a user meets the rule that closing is final, so it says so
     * rather than showing a form that cannot be saved.
     */
    public function testASealedEntryIsSentBackInsteadOfOpeningTheForm(): void
    {
        $this->configureRegime();
        $this->entry(120_000);

        $period = $this->entries()[0]->getPeriod();
        self::assertInstanceOf(AccountingPeriod::class, $period);
        self::getContainer()->get(AccountingPeriodManager::class)->close($period);

        $entry = $this->entries()[0];

        $this->client->request('GET', '/accounting/entry/' . $entry->getId() . '/edit');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/accounting/book/revenue', $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * The card a user opens to ask what state the books are in has to say how
     * far they are shut — a lock that is invisible is a lock that surprises.
     */
    public function testTheHomePageSaysHowFarTheBooksAreShut(): void
    {
        $this->configureRegime();
        $this->entry(120_000, 'today');

        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::LOCK_DATE, '2026-03-31');

        $text = $this->client->request('GET', '/accounting/')->filter('body')->text();

        // The suite runs in en_US, where a long date reads "March 31, 2026". The
        // page used to hardcode a day-first English format, which was right in
        // no locale at all.
        self::assertStringContainsString('Books shut up to March 31, 2026', $text);
    }

    /**
     * Shut is not only sealed. An entry in a period that ended before the lock
     * date is sent back from the form too, though nothing has been closed.
     */
    public function testAnEntryShutByTheLockDateIsSentBackInsteadOfOpeningTheForm(): void
    {
        $this->configureRegime();
        // Q1 2026, long over by the time anything runs this.
        $this->entry(120_000);

        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::LOCK_DATE, '2026-03-31');

        $entry = $this->entries()[0];

        self::assertFalse($entry->isLocked(), 'Nothing was sealed — the lock date alone sends this back.');

        $this->client->request('GET', '/accounting/entry/' . $entry->getId() . '/edit');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/accounting/book/revenue', $this->client->getResponse()->headers->get('Location'));
    }

    private function configureRegime(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set(SystemConfig::CURRENCY_CONFIG_PATH, 'EUR');
        $config->set(AccountingSettings::REGIME, 'fr_micro');
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    private function entry(int $amount, string $on = '2026-01-15'): void
    {
        $entry = new LedgerEntry()
            ->setBook(LedgerBook::Revenue)
            ->setEntryDate(new DateTimeImmutable($on))
            ->setLabel('Consulting work')
            ->setCounterpartyName('Johnston PLC')
            ->setAmount(BigInteger::of($amount))
            ->setCurrencyCode('EUR')
            ->setActivityNature(ActivityNature::ServicesBnc);

        $entry->setCompany($this->entityManager->find(Company::class, $this->company->getId()));

        self::getContainer()->get(AccountingPeriodManager::class)->assignPeriod($entry, PeriodType::Quarter);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    /**
     * @return list<LedgerEntry>
     */
    private function entries(): array
    {
        $this->entityManager->clear();

        return self::getContainer()->get(LedgerEntryRepository::class)->findBy([], ['entryDate' => 'ASC']);
    }
}
