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

use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\InvoiceBundle\Repository\CreditNoteRepository;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the mapping holds up against a real database — in particular that a
 * credit note line lands in the shared `invoice_lines` table under its own
 * discriminator, and comes back as a {@see CreditNoteLine}.
 */
#[CoversClass(CreditNote::class)]
#[CoversClass(CreditNoteLine::class)]
#[CoversClass(CreditNoteRepository::class)]
final class CreditNoteRepositoryTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private CreditNoteRepository $repository;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = self::getContainer()->get('doctrine');
        $this->repository = $registry->getRepository(CreditNote::class);
        $this->entityManager = $registry->getManager();
    }

    public function testRoundTripsThroughTheDatabase(): void
    {
        $company = CompanyFactory::createOne();

        $creditNote = CreditNoteFactory::createOne([
            'company' => $company,
            'creditNoteId' => 'AV-0001-2026',
            'reason' => CreditReason::Return,
            'status' => CreditNoteStatus::Issued,
            'creditNoteDate' => CarbonImmutable::parse('2026-03-14'),
        ]);

        $this->entityManager->clear();

        $found = $this->repository->find($creditNote->getId());

        self::assertInstanceOf(CreditNote::class, $found);
        self::assertSame('AV-0001-2026', $found->getCreditNoteId());
        self::assertSame(CreditReason::Return, $found->getReason());
        self::assertSame(CreditNoteStatus::Issued, $found->getStatus());
        self::assertSame('2026-03-14', $found->getCreditNoteDate()->format('Y-m-d'));
    }

    public function testLinesShareTheInvoiceLineTableUnderTheirOwnType(): void
    {
        $company = CompanyFactory::createOne();

        $creditNote = CreditNoteFactory::createOne([
            'company' => $company,
            'lines' => [
                new CreditNoteLine()
                    ->setCompany($company)
                    ->setDescription('Returned licence')
                    ->setPrice(2500)
                    ->setQty(1)
                    ->updateTotal(),
            ],
        ]);

        $id = $creditNote->getId();
        $this->entityManager->clear();

        $found = $this->repository->find($id);

        self::assertInstanceOf(CreditNote::class, $found);
        self::assertCount(1, $found->getLines());

        $line = $found->getLines()->first();
        self::assertInstanceOf(CreditNoteLine::class, $line);
        self::assertSame('Returned licence', $line->getDescription());

        $type = $this->entityManager->getConnection()->fetchOne(
            'SELECT type FROM invoice_lines WHERE description = ?',
            ['Returned licence'],
        );

        self::assertSame('credit_note', $type);
    }

    /**
     * Deleting the invoice must leave the correction standing: the credit note
     * was handed to the client and is owed, whatever became of the document it
     * answered to.
     */
    public function testSurvivesTheInvoiceItCorrects(): void
    {
        if (! $this->foreignKeysAreEnforced()) {
            self::markTestSkipped('ON DELETE SET NULL is a database-level action, and this SQLite connection has foreign keys switched off. The CI database matrix covers it.');
        }

        $company = CompanyFactory::createOne();
        $invoice = InvoiceFactory::createOne(['company' => $company]);

        $creditNote = CreditNoteFactory::createOne([
            'company' => $company,
            'creditedInvoice' => $invoice,
            'reason' => CreditReason::Cancellation,
        ]);

        $id = $creditNote->getId();

        $this->entityManager->remove($invoice);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->repository->find($id);

        self::assertInstanceOf(CreditNote::class, $found);
        self::assertNull($found->getCreditedInvoice());
    }

    /**
     * SQLite ignores foreign key actions unless the pragma is switched on, so a
     * test that asserts ON DELETE behaviour has nothing to observe there.
     */
    private function foreignKeysAreEnforced(): bool
    {
        $connection = $this->entityManager->getConnection();

        if (! $connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return true;
        }

        return '1' === (string) $connection->fetchOne('PRAGMA foreign_keys');
    }
}
