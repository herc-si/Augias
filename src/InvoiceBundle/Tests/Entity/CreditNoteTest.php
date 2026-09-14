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

namespace Augias\InvoiceBundle\Tests\Entity;

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\CreditReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreditNote::class)]
final class CreditNoteTest extends TestCase
{
    public function testStartsAsADraft(): void
    {
        self::assertSame(CreditNoteStatus::Draft, new CreditNote()->getStatus());
        self::assertFalse(new CreditNote()->isIssued());
    }

    public function testIsFixedOnceIssued(): void
    {
        $creditNote = new CreditNote()->setStatus(CreditNoteStatus::Issued);

        self::assertTrue($creditNote->isIssued());
    }

    public function testStaysFixedOnceSettled(): void
    {
        $creditNote = new CreditNote()->setStatus(CreditNoteStatus::Settled);

        self::assertTrue($creditNote->isIssued());
    }

    public function testAddingALineLinksItBack(): void
    {
        $creditNote = new CreditNote();
        $line = new CreditNoteLine();

        $creditNote->addLine($line);

        self::assertCount(1, $creditNote->getLines());
        self::assertSame($creditNote, $line->getCreditNote());
    }

    public function testRemovingALineUnlinksIt(): void
    {
        $creditNote = new CreditNote();
        $line = new CreditNoteLine();
        $creditNote->addLine($line);

        $creditNote->removeLine($line);

        self::assertCount(0, $creditNote->getLines());
        self::assertNull($line->getCreditNote());
    }

    /**
     * A rebate or a gesture answers to no invoice, so the link has to be
     * allowed to stay empty.
     */
    public function testCanStandWithoutAnInvoice(): void
    {
        $creditNote = new CreditNote()->setReason(CreditReason::CommercialGesture);

        self::assertNull($creditNote->getCreditedInvoice());
        self::assertTrue($creditNote->getReason()->standsAlone());
    }

    public function testPointsAtTheInvoiceItCorrects(): void
    {
        $invoice = new Invoice();
        $creditNote = new CreditNote()
            ->setReason(CreditReason::Cancellation)
            ->setCreditedInvoice($invoice);

        self::assertSame($invoice, $creditNote->getCreditedInvoice());
        self::assertFalse($creditNote->getReason()->standsAlone());
    }

    public function testCarriesAUuidFromTheStart(): void
    {
        self::assertNotSame('', new CreditNote()->getUuid()->toRfc4122());
    }

    public function testIsNamedByItsNumber(): void
    {
        $creditNote = new CreditNote()->setCreditNoteId('AV-0001-2026');

        self::assertSame('AV-0001-2026', (string) $creditNote);
    }
}
