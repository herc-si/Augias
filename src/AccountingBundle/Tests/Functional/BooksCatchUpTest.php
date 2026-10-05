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
use Augias\AccountingBundle\Action\CatchUp;
use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\PeriodType;
use Augias\AccountingBundle\Regime\Fr\MicroEntrepriseRegime;
use Augias\AccountingBundle\Repository\LedgerEntryRepository;
use Augias\AccountingBundle\Service\BooksCatchUp;
use Augias\AccountingBundle\Service\LedgerFeeder;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\SettingsBundle\SystemConfig;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use function uniqid;

/**
 * An invoice paid before accounting was turned on is in no book, and nothing
 * would ever put it there — until the user takes it in (06/10/2026).
 */
#[CoversClass(BooksCatchUp::class)]
#[CoversClass(CatchUp::class)]
#[CoversClass(LedgerFeeder::class)]
#[Group('functional')]
final class BooksCatchUpTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $browser;

    private EntityManagerInterface $entityManager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->browser = self::createClient();
        $this->browser->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->browser->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->client = ClientFactory::createOne(['name' => 'Johnston PLC', 'currencyCode' => 'EUR']);
    }

    public function testAPaymentFromBeforeTheBooksIsTakenInOnceAndOnlyOnce(): void
    {
        // Paid while no regime was chosen: no book to write it in.
        $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'));
        self::assertSame([], $this->entries());

        $this->configureRegime();
        $catchUp = $this->catchUp();

        $plan = $catchUp->plan($this->company(), new DateTimeImmutable('2026-01-01'));
        self::assertCount(1, $plan->entries);
        self::assertSame(LedgerBook::Revenue, $plan->entries[0]->getBook());
        self::assertSame('120000', (string) $plan->entries[0]->getAmount());
        self::assertSame([], $this->entries(), 'A plan writes nothing.');

        self::assertSame(1, $catchUp->run($this->company(), new DateTimeImmutable('2026-01-01')));

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame('2026-02-10', $entries[0]->getEntryDate()->format('Y-m-d'));
        self::assertNotNull($entries[0]->getPeriod(), 'Filed in its period, like any entry.');

        self::assertSame(0, $catchUp->run($this->company(), new DateTimeImmutable('2026-01-01')), 'Nothing is added twice.');
        self::assertCount(1, $this->entries());
    }

    public function testOnlyDocumentsFromTheChosenDayAreTakenIn(): void
    {
        $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'));
        $this->capturedPayment(30_000, new DateTimeImmutable('2026-05-04'));
        $this->configureRegime();

        $plan = $this->catchUp()->plan($this->company(), new DateTimeImmutable('2026-03-01'));

        self::assertCount(1, $plan->entries);
        self::assertSame('2026-05-04', $plan->entries[0]->getEntryDate()->format('Y-m-d'));
    }

    /**
     * What lies up to the lock date is final: an entry added there would
     * change figures already declared.
     */
    public function testNothingIsTakenInUpToTheLockDate(): void
    {
        $this->capturedPayment(120_000, new DateTimeImmutable('2026-02-10'));
        $this->configureRegime();
        self::getContainer()->get(SystemConfig::class)->set(AccountingSettings::LOCK_DATE, '2026-03-31');

        $plan = $this->catchUp()->plan($this->company(), new DateTimeImmutable('2026-01-01'));

        self::assertSame('2026-04-01', $plan->from->format('Y-m-d'));
        self::assertSame('2026-04-01', $plan->earliestStart?->format('Y-m-d'));
        self::assertTrue($plan->isEmpty());
    }

    public function testItStartsAtTheFinancialYearByDefault(): void
    {
        $this->configureRegime();
        $config = self::getContainer()->get(SystemConfig::class);
        $today = new DateTimeImmutable('2026-10-06');

        self::assertSame('2026-01-01', $this->catchUp()->defaultStart($this->company(), $today)->format('Y-m-d'));

        $config->set(AccountingSettings::FISCAL_YEAR_START_MONTH, '4');
        self::assertSame('2026-04-01', $this->catchUp()->defaultStart($this->company(), $today)->format('Y-m-d'));
    }

    public function testTheHomePageOffersToTakeThemInAndThePageDoesIt(): void
    {
        $this->capturedPayment(120_000, new DateTimeImmutable('today'));
        $this->configureRegime();

        $crawler = $this->browser->request('GET', '/accounting/');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('missing from your books', $crawler->filter('body')->text());

        $crawler = $this->browser->request('GET', '/accounting/catch-up');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('1,200.00', $crawler->filter('table.card-table')->text());

        $this->browser->submit($crawler->filter('form[method="post"][action="/accounting/catch-up"]')->form());
        self::assertSame('/accounting/', $this->browser->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->entries());

        $crawler = $this->browser->request('GET', '/accounting/');
        self::assertStringNotContainsString('missing from your books', $crawler->filter('body')->text());
    }

    private function configureRegime(): void
    {
        $config = self::getContainer()->get(SystemConfig::class);

        $config->set(AccountingSettings::REGIME, MicroEntrepriseRegime::CODE);
        $config->set(AccountingSettings::PRIMARY_ACTIVITY, ActivityNature::ServicesBnc->value);
        $config->set(AccountingSettings::DECLARATION_PERIODICITY, PeriodType::Quarter->value);
    }

    private function catchUp(): BooksCatchUp
    {
        return self::getContainer()->get(BooksCatchUp::class);
    }

    private function company(): Company
    {
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function capturedPayment(int $amount, DateTimeImmutable $completed): void
    {
        $invoice = new Invoice();
        $invoice->setClient($this->entityManager->find(Client::class, $this->client->getId()));
        $invoice->setStatus(InvoiceStatus::Paid);
        $invoice->setInvoiceId('INV-' . uniqid());
        $invoice->setInvoiceDate($completed);
        $invoice->setCompany($this->company());
        $invoice->addLine(new Line()->setDescription('Consulting')->setPrice($amount)->setQty(1));
        $this->entityManager->persist($invoice);

        $payment = new Payment();
        $payment->setTotalAmount($amount);
        $payment->setCurrencyCode('EUR');
        $payment->setInvoice($invoice);
        $payment->setClient($invoice->getClient());
        $payment->setStatus(PaymentStatus::Captured);
        $payment->setCompleted($completed);
        $payment->setCompany($this->company());
        $this->entityManager->persist($payment);

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
