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

namespace Augias\InvoiceBundle\Manager;

use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditReason;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Moves a credit note between its form data and the entity.
 *
 * The mirror of an invoice is not a copy of it: quantities and prices carry
 * over as they stand, because a credit note states what is being given back,
 * positively, and the sign is applied where the document is read.
 */
final readonly class CreditNoteFormManager
{
    public function createFromDTO(CreditNoteFormDTO $dto): CreditNote
    {
        $client = $dto->client;

        if (null === $client) {
            throw new InvalidArgumentException('A credit note needs a client.');
        }

        $creditNote = new CreditNote();
        $creditNote->setClient($client);

        $this->apply($creditNote, $dto);

        foreach ($dto->users as $user) {
            $creditNote->addUser($user);
        }

        return $creditNote;
    }

    public function updateFromDTO(CreditNote $creditNote, CreditNoteFormDTO $dto): void
    {
        $this->apply($creditNote, $dto);

        $creditNote->getUsers()->clear();

        foreach ($dto->users as $user) {
            $creditNote->addUser($user);
        }
    }

    public function createDTOFrom(CreditNote $creditNote): CreditNoteFormDTO
    {
        $dto = new CreditNoteFormDTO();
        $dto->client = $creditNote->getClient();
        $dto->creditedInvoice = $creditNote->getCreditedInvoice();
        $dto->reason = $creditNote->getReason();
        $dto->creditNoteId = $creditNote->getCreditNoteId();
        $dto->creditNoteDate = $creditNote->getCreditNoteDate();
        $dto->discount = $creditNote->getDiscount();
        $dto->terms = $creditNote->getTerms();
        $dto->notes = $creditNote->getNotes();
        $dto->total = (string) $creditNote->getTotal();
        $dto->baseTotal = (string) $creditNote->getBaseTotal();
        $dto->tax = (string) $creditNote->getTax();

        foreach ($creditNote->getLines() as $line) {
            $dto->lines->add($line);
        }

        foreach ($creditNote->getUsers() as $user) {
            $dto->users->add($user);
        }

        return $dto;
    }

    /**
     * Pre-fills a credit note that cancels an invoice in full: every line
     * mirrored as it stands, the same client, the same contacts.
     */
    public function cancellationOf(Invoice $invoice): CreditNoteFormDTO
    {
        $dto = new CreditNoteFormDTO();
        $dto->client = $invoice->getClient();
        $dto->creditedInvoice = $invoice;
        $dto->reason = CreditReason::Cancellation;
        $dto->creditNoteDate = CarbonImmutable::now();
        $dto->terms = $invoice->getTerms();
        $dto->discount = $invoice->getDiscount();

        foreach ($invoice->getLines() as $line) {
            $dto->lines->add(
                new CreditNoteLine()
                    ->setDescription($line->getDescription())
                    ->setPrice($line->getPrice())
                    ->setQty($line->getQty())
                    // A credit note takes back what the invoice charged, in the
                    // same terms. Dropping the mark here would credit a
                    // disbursement as turnover and leave the books short.
                    ->setDisbursement($line->isDisbursement())
                    // Goods credited are goods: their VAT is taken back on the
                    // day the credit note is issued, as it fell due on the day
                    // the invoice was.
                    ->setSupplyType($line->getSupplyType())
                    ->updateTotal(),
            );
        }

        foreach ($invoice->getUsers() as $user) {
            $dto->users->add($user);
        }

        return $dto;
    }

    private function apply(CreditNote $creditNote, CreditNoteFormDTO $dto): void
    {
        $creditNote->setCreditNoteId($dto->creditNoteId);
        $creditNote->setCreditNoteDate($dto->creditNoteDate ?? CarbonImmutable::now());
        $creditNote->setCreditedInvoice($dto->creditedInvoice);
        $creditNote->setTerms($dto->terms);
        $creditNote->setNotes($dto->notes);
        $creditNote->setTotal($dto->total);
        $creditNote->setBaseTotal($dto->baseTotal);
        $creditNote->setTax($dto->tax);

        if (null !== $dto->reason) {
            $creditNote->setReason($dto->reason);
        }

        if (null !== $dto->discount) {
            $creditNote->setDiscount($dto->discount);
        }

        $creditNote->getLines()->clear();

        foreach ($dto->lines as $line) {
            $creditNote->addLine($line);
        }
    }
}
