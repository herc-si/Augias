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

namespace Augias\InvoiceBundle\Tests\Functional;

use Augias\DataGridBundle\Filter\SortFilter;
use Augias\DataGridBundle\GridBuilder\Order\NumberedOrder;
use Augias\DataGridBundle\Source\ORMSource;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\DataGrid\InvoiceGrid;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use function array_map;

/**
 * Sorting a column of invoice numbers has to put the tenth invoice after the
 * second one. Run against a real database rather than a mocked query builder,
 * because the claim is about what the database does with the ordering — every
 * platform the project supports has to agree.
 */
#[CoversClass(SortFilter::class)]
#[CoversClass(NumberedOrder::class)]
final class InvoiceNumberSortTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testInvoiceNumbersSortByTheirNumberNotCharacterByCharacter(): void
    {
        foreach (['FACT-10', 'FACT-2', 'FACT-11', 'FACT-1'] as $number) {
            InvoiceFactory::createOne(['invoiceId' => $number]);
        }

        self::assertSame(['FACT-1', 'FACT-2', 'FACT-10', 'FACT-11'], $this->sortedNumbers(natural: true));

        // What the grid did before, and what the report was about.
        self::assertSame(['FACT-1', 'FACT-10', 'FACT-11', 'FACT-2'], $this->sortedNumbers(natural: false));
    }

    /**
     * The list has to open on something; before this it opened on whatever the
     * database returned for invoices sharing a date, which is stable enough to
     * look deliberate and arbitrary enough to be wrong.
     */
    public function testTheDefaultOrderBreaksATieOnTheDateWithTheNumber(): void
    {
        $sameDay = new DateTimeImmutable('2026-03-01');

        foreach (['FACT-1', 'FACT-10', 'FACT-2'] as $number) {
            InvoiceFactory::createOne(['invoiceId' => $number, 'invoiceDate' => $sameDay]);
        }

        $grid = self::getContainer()->get(InvoiceGrid::class);
        self::assertInstanceOf(InvoiceGrid::class, $grid);
        $grid->initialize([]);

        $source = self::getContainer()->get(ORMSource::class);
        self::assertInstanceOf(ORMSource::class, $source);

        /** @var list<Invoice> $invoices */
        $invoices = $source->fetch($grid)->getQueryBuilder()->getQuery()->getResult();

        self::assertSame(
            ['FACT-10', 'FACT-2', 'FACT-1'],
            array_map(static fn (Invoice $invoice): string => $invoice->getInvoiceId(), $invoices),
        );
    }

    /**
     * @return list<string>
     */
    private function sortedNumbers(bool $natural): array
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $queryBuilder = $entityManager->createQueryBuilder()
            ->select(ORMSource::ALIAS)
            ->from(Invoice::class, ORMSource::ALIAS);

        new SortFilter('invoiceId', 'ASC', $natural)->filter($queryBuilder, null);

        /** @var list<Invoice> $invoices */
        $invoices = $queryBuilder->getQuery()->getResult();

        return array_map(static fn (Invoice $invoice): string => $invoice->getInvoiceId(), $invoices);
    }
}
