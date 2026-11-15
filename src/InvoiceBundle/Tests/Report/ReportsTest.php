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

namespace Augias\InvoiceBundle\Tests\Report;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Enum\ClientStatus;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Action\Report\Index;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Report\AgedReceivables;
use Augias\InvoiceBundle\Report\RevenueByClient;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\PaymentBundle\Enum\PaymentStatus;
use Augias\PaymentBundle\Test\Factory\PaymentFactory;
use Augias\PaymentBundle\Test\Factory\PaymentMethodFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Brick\Math\BigInteger;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;
use Zenstruck\Browser\Test\HasBrowser;
use function array_column;
use function array_keys;
use function array_map;

/**
 * The two reports say what the documents say: who owes what and how late,
 * and what each client brought in over a year.
 */
#[CoversClass(AgedReceivables::class)]
#[CoversClass(RevenueByClient::class)]
#[CoversClass(Index::class)]
#[Group('functional')]
final class ReportsTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    private const string TODAY = '2026-11-15';

    public function testAgedReceivablesSortEachUnpaidBalanceByHowLateItIs(): void
    {
        $dupont = $this->client('Atelier Dupont');
        $petit = $this->client('Boulangerie Petit');

        $this->invoice($dupont, InvoiceStatus::Pending, balance: 10000, due: '2026-12-01');   // not due
        $this->invoice($dupont, InvoiceStatus::Pending, balance: 500, due: null);             // no due date: never late
        $this->invoice($dupont, InvoiceStatus::Overdue, balance: 20000, due: '2026-11-14');   // 1 day late
        $this->invoice($dupont, InvoiceStatus::Overdue, balance: 30000, due: '2026-10-16');   // 30 days late
        $this->invoice($dupont, InvoiceStatus::Overdue, balance: 40000, due: '2026-10-15');   // 31 days late
        $this->invoice($petit, InvoiceStatus::Overdue, balance: 700000, due: '2026-07-01');   // well over 90
        $this->invoice($petit, InvoiceStatus::Overdue, balance: 6000, due: '2026-08-17');     // 90 days late
        // Not receivables: paid, draft, nothing left to pay.
        $this->invoice($petit, InvoiceStatus::Paid, balance: 0, due: '2026-01-01');
        $this->invoice($petit, InvoiceStatus::Draft, balance: 99999, due: '2026-01-01');
        $this->invoice($petit, InvoiceStatus::Pending, balance: 0, due: '2026-01-01');

        $report = $this->aged();

        self::assertSame(['EUR'], array_keys($report));
        self::assertSame(['Boulangerie Petit', 'Atelier Dupont'], array_column($report['EUR']['rows'], 'client'), 'The largest debt first.');
        self::assertSame([0, 0, 0, 6000, 700000], $this->ints($report['EUR']['rows'][0]['buckets']));
        self::assertSame([10500, 50000, 40000, 0, 0], $this->ints($report['EUR']['rows'][1]['buckets']));
        self::assertSame(100500, $report['EUR']['rows'][1]['total']->toInt());
        self::assertSame([10500, 50000, 40000, 6000, 700000], $this->ints($report['EUR']['totals']));
        self::assertSame(806500, $report['EUR']['total']->toInt());
    }

    public function testCurrenciesAreNeverAdded(): void
    {
        $this->invoice($this->client('Atelier Dupont'), InvoiceStatus::Pending, balance: 10000, due: '2026-12-01');
        $this->invoice($this->client('Acme Inc', 'USD'), InvoiceStatus::Pending, balance: 25000, due: '2026-12-01');

        $report = $this->aged();

        self::assertSame(['EUR', 'USD'], array_keys($report));
        self::assertSame(10000, $report['EUR']['total']->toInt());
        self::assertSame(25000, $report['USD']['total']->toInt());
    }

    public function testRevenueCountsWhatWasIssuedAndReceivedInTheYear(): void
    {
        $dupont = $this->client('Atelier Dupont');
        $petit = $this->client('Boulangerie Petit');

        // 1 200,00 incl. 200,00 of tax and 100,00 of disbursements: 900,00 of revenue.
        $this->invoice($dupont, InvoiceStatus::Paid, total: 120000, tax: 20000, disbursements: 10000, date: '2026-03-10');
        $this->invoice($dupont, InvoiceStatus::Pending, total: 60000, tax: 10000, date: '2026-12-31');
        $this->invoice($petit, InvoiceStatus::Overdue, total: 24000, tax: 4000, date: '2026-01-01');
        // Left out: a draft, a cancelled invoice, another year.
        $this->invoice($dupont, InvoiceStatus::Draft, total: 999900, tax: 0, date: '2026-05-01');
        $this->invoice($dupont, InvoiceStatus::Cancelled, total: 999900, tax: 0, date: '2026-05-01');
        $this->invoice($dupont, InvoiceStatus::Paid, total: 999900, tax: 0, date: '2025-12-31');

        $this->creditNote($dupont, CreditNoteStatus::Issued, total: 12000, tax: 2000);
        $this->creditNote($dupont, CreditNoteStatus::Draft, total: 77700, tax: 0);

        PaymentFactory::createOne(['company' => $this->company, 'client' => $dupont, 'status' => PaymentStatus::Captured, 'totalAmount' => 120000, 'currencyCode' => 'EUR', 'created' => new DateTimeImmutable('2026-03-20 10:00:00'), 'method' => PaymentMethodFactory::new(['company' => $this->company, 'gatewayName' => 'cash'])]);
        PaymentFactory::createOne(['company' => $this->company, 'client' => $dupont, 'status' => PaymentStatus::Failed, 'totalAmount' => 55500, 'currencyCode' => 'EUR', 'created' => new DateTimeImmutable('2026-03-21 10:00:00'), 'method' => PaymentMethodFactory::new(['company' => $this->company, 'gatewayName' => 'cash'])]);
        PaymentFactory::createOne(['company' => $this->company, 'client' => $dupont, 'status' => PaymentStatus::Captured, 'totalAmount' => 44400, 'currencyCode' => 'EUR', 'created' => new DateTimeImmutable('2025-12-31 23:00:00'), 'method' => PaymentMethodFactory::new(['company' => $this->company, 'gatewayName' => 'cash'])]);

        $report = $this->revenue(2026);

        self::assertSame(['EUR'], array_keys($report));
        [$first, $second] = $report['EUR']['rows'];

        self::assertSame('Atelier Dupont', $first['client']);
        self::assertSame(2, $first['invoices']);
        self::assertSame(140000, $first['invoiced']->toInt(), '900,00 + 500,00.');
        self::assertSame(10000, $first['credited']->toInt());
        self::assertSame(130000, $first['net']->toInt());
        self::assertSame(120000, $first['received']->toInt());

        self::assertSame('Boulangerie Petit', $second['client']);
        self::assertSame(20000, $second['net']->toInt());
        self::assertSame(0, $second['received']->toInt());

        self::assertSame(150000, $report['EUR']['net']->toInt());
        self::assertSame(120000, $report['EUR']['received']->toInt());

        self::assertSame(['Atelier Dupont'], array_column($this->revenue(2025)['EUR']['rows'], 'client'));
        self::assertSame([], $this->revenue(2024));
    }

    public function testThePageShowsBothReportsAndExportsThem(): void
    {
        $today = new DateTimeImmutable('today');
        $dupont = $this->client('Atelier Dupont');
        $this->invoice($dupont, InvoiceStatus::Overdue, balance: 123456, due: $today->modify('-45 days')->format('Y-m-d'), total: 123456, tax: 20576, date: $today->format('Y') . '-01-02');

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $browser = $this->browser()->actingAs($user);

        $browser
            ->visit('/invoices/reports')
            ->assertSuccessful()
            ->assertSeeIn('.report-table', 'Atelier Dupont')
            ->assertSeeElement('.report-total')
            ->assertSeeElement('a[href$="/invoices/reports/aged.csv"]')
            ->visit('/invoices/reports/revenue')
            ->assertSuccessful()
            ->assertSeeIn('.report-table', 'Atelier Dupont')
            ->visit('/invoices/reports/revenue?year=' . ((int) $today->format('Y') - 1))
            ->assertSuccessful()
            ->assertSeeElement('.report-empty');

        $csv = $browser->visit('/invoices/reports/aged.csv')->assertSuccessful()->content();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Atelier Dupont;EUR;0,00;0,00;1234,56;0,00;0,00;1234,56', $csv);

        $csv = $browser->visit('/invoices/reports/revenue.csv')->assertSuccessful()->content();
        self::assertStringContainsString('Atelier Dupont;EUR;1;1028,80;0,00;1028,80;0,00', $csv);
    }

    /**
     * @return array<string, mixed>
     */
    private function aged(): array
    {
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        return self::getContainer()->get(AgedReceivables::class)->asOf(new DateTimeImmutable(self::TODAY . ' 15:30:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private function revenue(int $year): array
    {
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        return self::getContainer()->get(RevenueByClient::class)->forYear($year);
    }

    private function client(string $name, string $currency = 'EUR'): Client
    {
        return ClientFactory::createOne(['company' => $this->company, 'name' => $name, 'status' => ClientStatus::Active, 'currencyCode' => $currency]);
    }

    private function invoice(Client $client, InvoiceStatus $status, int $balance = 0, ?string $due = '2026-12-01', int $total = 0, int $tax = 0, int $disbursements = 0, string $date = '2026-06-01'): void
    {
        $invoice = InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => $status,
            'due' => null === $due ? null : new DateTimeImmutable($due),
            'invoiceDate' => new DateTimeImmutable($date),
        ]);

        $this->amounts(Invoice::class, $invoice->getId(), ['balance' => $balance, 'total' => $total, 'tax' => $tax, 'disbursementTotal' => $disbursements]);
    }

    private function creditNote(Client $client, CreditNoteStatus $status, int $total, int $tax): void
    {
        $creditNote = CreditNoteFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => $status, 'creditNoteDate' => new DateTimeImmutable('2026-04-01')]);

        $this->amounts(CreditNote::class, $creditNote->getId(), ['total' => $total, 'tax' => $tax, 'disbursementTotal' => 0]);
    }

    /**
     * The totals of a document are worked out from its lines when it is saved,
     * so the figures a report test needs are written after the fact.
     *
     * @param class-string       $class
     * @param array<string, int> $amounts
     */
    private function amounts(string $class, ?Ulid $id, array $amounts): void
    {
        $update = $this->em->createQueryBuilder()->update($class, 'd')->where('d.id = :id')->setParameter('id', $id, UlidType::NAME);

        foreach ($amounts as $field => $amount) {
            $update->set('d.' . $field, ':' . $field)->setParameter($field, BigInteger::of($amount), BigIntegerType::NAME);
        }

        $update->getQuery()->execute();
    }

    /**
     * @param list<BigInteger> $amounts
     *
     * @return list<int>
     */
    private function ints(array $amounts): array
    {
        return array_map(static fn (BigInteger $amount): int => $amount->toInt(), $amounts);
    }
}
