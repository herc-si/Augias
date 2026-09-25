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

namespace Augias\InvoiceBundle\Tests\Repository;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Enum\RecurringInvoiceStatus;
use Augias\InvoiceBundle\Recurring\RecurringSchedule;
use Augias\InvoiceBundle\Repository\RecurringInvoiceRepository;
use Augias\InvoiceBundle\Test\Factory\RecurringInvoiceFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use function array_map;
use function sort;

#[CoversClass(RecurringInvoiceRepository::class)]
final class RecurringInvoiceRepositoryTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private RecurringInvoiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 on 1 February: late enough in the day that a datetime bound
        // would fall after a DATE column holding today.
        $this->repository = new RecurringInvoiceRepository(
            self::getContainer()->get('doctrine'),
            self::getContainer()->get(RecurringSchedule::class),
            new MockClock(new DateTimeImmutable('2024-02-01 10:00:00', new DateTimeZone('UTC'))),
        );
    }

    /**
     * The window is whole days at both ends: a series that ends today, and one
     * that starts on the last day of the window, are both upcoming.
     */
    public function testUpcomingWindowIncludesBothBoundaryDays(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company]);

        $endsToday = RecurringInvoiceFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'status' => RecurringInvoiceStatus::Active,
            'dateStart' => new DateTimeImmutable('2024-01-01'),
            'dateEnd' => new DateTimeImmutable('2024-02-01'),
        ]);

        $startsOnLastDay = RecurringInvoiceFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'status' => RecurringInvoiceStatus::Active,
            'dateStart' => new DateTimeImmutable('2024-02-08'),
        ]);

        RecurringInvoiceFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'status' => RecurringInvoiceStatus::Active,
            'dateStart' => new DateTimeImmutable('2024-01-01'),
            'dateEnd' => new DateTimeImmutable('2024-01-31'),
        ]);

        RecurringInvoiceFactory::createOne([
            'client' => $client,
            'company' => $this->company,
            'status' => RecurringInvoiceStatus::Active,
            'dateStart' => new DateTimeImmutable('2024-02-09'),
        ]);

        $upcoming = array_map(
            static fn (RecurringInvoice $invoice): string => $invoice->getId()->toBase32(),
            $this->repository->getUpcomingRecurringInvoices(7, 10),
        );
        sort($upcoming);

        $expected = [$endsToday->getId()->toBase32(), $startsOnLastDay->getId()->toBase32()];
        sort($expected);

        self::assertSame($expected, $upcoming);
    }
}
