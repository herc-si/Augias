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
use Augias\DataGridBundle\Source\ORMSource;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
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
