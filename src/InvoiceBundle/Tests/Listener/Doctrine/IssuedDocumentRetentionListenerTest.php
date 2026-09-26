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

namespace Augias\InvoiceBundle\Tests\Listener\Doctrine;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Exception\DocumentMustBeKept;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Listener\Doctrine\IssuedDocumentRetentionListener;
use Augias\InvoiceBundle\Repository\InvoiceRepository;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Listener\Doctrine\QuoteRemovalListener;
use Augias\QuoteBundle\Repository\QuoteRepository;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

/**
 * An issued invoice or credit note is kept, whichever way someone tries to
 * delete it — and a draft still goes.
 */
#[CoversClass(IssuedDocumentRetentionListener::class)]
#[CoversClass(QuoteRemovalListener::class)]
final class IssuedDocumentRetentionListenerTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    private int $number = 0;

    public function testAnIssuedInvoiceCannotBeDeleted(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Pending);

        try {
            $this->em()->remove($invoice);
            self::fail('An issued invoice was removed.');
        } catch (DocumentMustBeKept $refusal) {
            self::assertStringContainsString($invoice->getInvoiceId(), $refusal->getMessage());
        }

        $this->assertStillThere(Invoice::class, $invoice->getId());
    }

    /**
     * Cancelled or archived, it was issued all the same.
     */
    public function testACancelledInvoiceIsKeptToo(): void
    {
        $this->expectException(DocumentMustBeKept::class);

        $this->em()->remove($this->invoice(InvoiceStatus::Cancelled));
    }

    public function testADraftInvoiceCanBeDeleted(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Draft);
        $id = $invoice->getId();

        $this->em()->remove($invoice);
        $this->em()->flush();
        $this->em()->clear();

        self::assertNull($this->em()->find(Invoice::class, $id));
    }

    public function testAnIssuedCreditNoteCannotBeDeleted(): void
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $this->client(),
            'status' => CreditNoteStatus::Issued,
        ]);
        self::assertInstanceOf(CreditNote::class, $creditNote);

        $this->expectException(DocumentMustBeKept::class);

        $this->em()->remove($creditNote);
    }

    /**
     * A selection that mixes drafts and issued invoices deletes none of them.
     */
    public function testDeletingASelectionWithAnIssuedInvoiceDeletesNothing(): void
    {
        $draft = $this->invoice(InvoiceStatus::Draft);
        $issued = $this->invoice(InvoiceStatus::Paid);

        try {
            self::getContainer()->get(InvoiceRepository::class)->deleteInvoices([(string) $draft->getId(), (string) $issued->getId()]);
            self::fail('The selection was deleted.');
        } catch (DocumentMustBeKept $refusal) {
            self::assertStringContainsString($issued->getInvoiceId(), $refusal->getMessage());
            self::assertStringNotContainsString($draft->getInvoiceId(), $refusal->getMessage());
        }

        $this->assertStillThere(Invoice::class, $draft->getId());
        $this->assertStillThere(Invoice::class, $issued->getId());
    }

    /**
     * A client goes with everything it owns: one that was sent an invoice
     * stays, and the message says to archive it.
     */
    public function testAClientWithAnIssuedInvoiceCannotBeDeleted(): void
    {
        $client = $this->client();
        $invoice = $this->invoice(InvoiceStatus::Overdue, $client);
        $invoice->archive();
        $this->em()->flush();

        try {
            self::getContainer()->get(ClientRepository::class)->delete($client);
            self::fail('The client was deleted with its issued invoice.');
        } catch (DocumentMustBeKept $refusal) {
            self::assertStringContainsString('Archive the client instead', $refusal->getMessage());
        }

        $this->assertStillThere(Client::class, $client->getId());
        $this->assertStillThere(Invoice::class, $invoice->getId());
    }

    public function testAClientWithOnlyDraftsCanBeDeleted(): void
    {
        $client = $this->client();
        $draft = $this->invoice(InvoiceStatus::Draft, $client);
        $invoiceId = $draft->getId();
        $clientId = $client->getId();

        self::getContainer()->get(ClientRepository::class)->delete($client);
        $this->em()->clear();

        self::assertNull($this->em()->find(Client::class, $clientId));
        self::assertNull($this->em()->find(Invoice::class, $invoiceId));
    }

    /**
     * The invoice a quote became outlives the quote; only the link goes. On
     * SQLite, which enforces no foreign key, the invoice used to point at a
     * quote that was no longer there, and its page could not be opened.
     */
    public function testDeletingAQuoteKeepsItsInvoice(): void
    {
        $client = $this->client();
        $quote = QuoteFactory::createOne(['company' => $this->company, 'client' => $client]);
        self::assertInstanceOf(Quote::class, $quote);
        $invoice = $this->invoice(InvoiceStatus::Pending, $client);
        $invoice->setQuote($quote);
        $this->em()->flush();

        $quoteId = $quote->getId();

        self::getContainer()->get(QuoteRepository::class)->deleteQuotes([(string) $quoteId]);
        $this->em()->clear();

        $kept = $this->em()->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $kept);
        self::assertNull($kept->getQuote());
        self::assertNull($this->em()->find(Quote::class, $quoteId));
    }

    private function invoice(InvoiceStatus $status, ?Client $client = null): Invoice
    {
        $invoice = InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $client ?? $this->client(),
            'status' => $status,
            'invoiceId' => 'RET-' . ++$this->number,
        ]);
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    private function client(): Client
    {
        $client = ClientFactory::createOne(['company' => $this->company]);
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    /**
     * @param class-string $class
     */
    private function assertStillThere(string $class, ?Ulid $id): void
    {
        $this->em()->clear();
        $filters = $this->em()->getFilters();
        $filters->disable('archivable');

        try {
            self::assertNotNull($this->em()->find($class, $id));
        } finally {
            $filters->enable('archivable');
        }
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
