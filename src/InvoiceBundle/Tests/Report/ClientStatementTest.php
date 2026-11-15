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
use Augias\InvoiceBundle\Action\Report\Statement;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteAllocation;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Report\ClientStatement;
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
use function array_map;

/**
 * A client's statement of account: every movement in date order, and the
 * balance it leaves.
 */
#[CoversClass(ClientStatement::class)]
#[CoversClass(Statement::class)]
#[Group('functional')]
final class ClientStatementTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testEveryMovementIsListedWithTheBalanceItLeaves(): void
    {
        $client = $this->history();

        $statement = $this->statement($client);

        self::assertSame(
            [
                ['2025-12-01', 'invoice', 'FACT-1', 30000, 0, 30000],
                ['2026-01-10', 'invoice', 'FACT-2', 120000, 0, 150000],
                ['2026-01-20', 'payment', 'FACT-2', 0, 50000, 100000],
                ['2026-02-01', 'credit_note', 'AV-1', 0, 20000, 80000],
                ['2026-02-10', 'refund', 'AV-1', 5000, 0, 85000],
            ],
            array_map(
                static fn (array $line): array => [$line['date']->format('Y-m-d'), $line['kind'], $line['reference'], $line['debit']->toInt(), $line['credit']->toInt(), $line['balance']->toInt()],
                $statement['lines'],
            ),
            'The draft, the cancelled invoice, the failed payment and the other client are not there.',
        );
        self::assertSame(0, $statement['opening']->toInt());
        self::assertSame(155000, $statement['debit']->toInt());
        self::assertSame(70000, $statement['credit']->toInt());
        self::assertSame(85000, $statement['closing']->toInt());
    }

    public function testAYearStartsFromWhatWasOwedBeforeIt(): void
    {
        $client = $this->history();

        $statement = $this->statement($client, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));

        self::assertSame(30000, $statement['opening']->toInt());
        self::assertCount(4, $statement['lines']);
        self::assertSame(125000, $statement['debit']->toInt(), 'Of the year only.');
        self::assertSame(85000, $statement['closing']->toInt());

        $before = $this->statement($client, new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
        self::assertSame(0, $before['opening']->toInt());
        self::assertCount(1, $before['lines']);
        self::assertSame(30000, $before['closing']->toInt(), 'What came after the period is not in its closing balance.');
    }

    public function testThePageAndThePdfAreReachedFromTheClient(): void
    {
        $client = $this->history();
        $id = $client->getId()->toBase32();
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $browser = $this->browser()->actingAs($user);

        $browser
            ->visit('/clients/view/' . $id)
            ->assertSuccessful()
            ->assertSeeElement('a[href$="/invoices/reports/statement/' . $id . '"]')
            ->visit('/invoices/reports/statement/' . $id)
            ->assertSuccessful()
            ->assertSeeIn('.client-statement', 'FACT-2')
            ->assertSeeIn('.client-statement', 'AV-1')
            ->assertSeeElement('.statement-closing')
            ->visit('/invoices/reports/statement/' . $id . '?year=2026')
            ->assertSuccessful()
            ->assertSeeElement('.statement-opening')
            ->visit('/invoices/reports/statement/' . $id . '?year=2001')
            ->assertSuccessful()
            ->assertSeeElement('.statement-empty');

        $pdf = $browser->visit('/invoices/reports/statement/' . $id . '.pdf?year=2026')->assertSuccessful()->content();
        self::assertStringStartsWith('%PDF', $pdf);
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(Client $client, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        return self::getContainer()->get(ClientStatement::class)->forClient($client, $from, $to);
    }

    private function history(): Client
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont', 'status' => ClientStatus::Active, 'currencyCode' => 'EUR']);
        $other = ClientFactory::createOne(['company' => $this->company, 'name' => 'Boulangerie Petit', 'status' => ClientStatus::Active, 'currencyCode' => 'EUR']);

        $this->invoice($client, InvoiceStatus::Paid, 'FACT-1', 30000, '2025-12-01');
        $invoice = $this->invoice($client, InvoiceStatus::Pending, 'FACT-2', 120000, '2026-01-10');
        $this->invoice($client, InvoiceStatus::Draft, 'FACT-DRAFT', 777700, '2026-01-11');
        $this->invoice($client, InvoiceStatus::Cancelled, 'FACT-3', 888800, '2026-01-12');
        $this->invoice($other, InvoiceStatus::Pending, 'FACT-4', 999900, '2026-01-13');

        $method = PaymentMethodFactory::createOne(['company' => $this->company, 'gatewayName' => 'cash']);
        PaymentFactory::createOne(['company' => $this->company, 'client' => $client, 'invoice' => $invoice, 'method' => $method, 'status' => PaymentStatus::Captured, 'totalAmount' => 50000, 'currencyCode' => 'EUR', 'created' => new DateTimeImmutable('2026-01-20 09:00:00')]);
        PaymentFactory::createOne(['company' => $this->company, 'client' => $client, 'invoice' => $invoice, 'method' => $method, 'status' => PaymentStatus::Failed, 'totalAmount' => 66600, 'currencyCode' => 'EUR', 'created' => new DateTimeImmutable('2026-01-21 09:00:00')]);

        $creditNote = CreditNoteFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => CreditNoteStatus::Issued, 'creditNoteId' => 'AV-1', 'creditNoteDate' => new DateTimeImmutable('2026-02-01')]);
        $this->total(CreditNote::class, $creditNote->getId(), 20000);
        CreditNoteFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => CreditNoteStatus::Draft, 'creditNoteId' => 'AV-2', 'creditNoteDate' => new DateTimeImmutable('2026-02-02')]);

        $refund = new CreditNoteAllocation();
        $refund->setCreditNote($creditNote)->setKind(AllocationKind::Refund)->setAmount(5000)->setAllocatedOn(new DateTimeImmutable('2026-02-10'));
        $this->em->persist($refund);
        // Set against an invoice: no line of its own, the credit note already came off.
        $offset = new CreditNoteAllocation();
        $offset->setCreditNote($creditNote)->setInvoice($invoice)->setKind(AllocationKind::Offset)->setAmount(15000)->setAllocatedOn(new DateTimeImmutable('2026-02-11'));
        $this->em->persist($offset);
        $this->em->flush();

        return $client;
    }

    private function invoice(Client $client, InvoiceStatus $status, string $number, int $total, string $date): Invoice
    {
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => $status, 'invoiceId' => $number, 'invoiceDate' => new DateTimeImmutable($date)]);
        $this->total(Invoice::class, $invoice->getId(), $total);

        return $invoice;
    }

    /**
     * A document's total is worked out from its lines when it is saved, so the
     * figure the statement needs is written after the fact.
     *
     * @param class-string $class
     */
    private function total(string $class, ?Ulid $id, int $total): void
    {
        $this->em->createQueryBuilder()
            ->update($class, 'd')
            ->set('d.total', ':total')
            ->where('d.id = :id')
            ->setParameter('total', BigInteger::of($total), BigIntegerType::NAME)
            ->setParameter('id', $id, UlidType::NAME)
            ->getQuery()
            ->execute();
    }
}
