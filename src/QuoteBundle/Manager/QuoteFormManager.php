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

namespace Augias\QuoteBundle\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\QuoteBundle\DTO\QuoteFormDTO;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteClientMode;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\InvoiceTax;
use Augias\TaxBundle\Entity\Tax;
use InvalidArgumentException;

/**
 * Manager for handling Quote form DTO transformations
 */
final readonly class QuoteFormManager
{
    public function __construct(
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * Creates a new Quote entity from DTO
     * Returns an unsaved Quote entity (client is unpersisted if mode=New)
     * Caller must persist the quote (client will cascade)
     */
    public function createQuoteFromDTO(QuoteFormDTO $dto): Quote
    {
        $quote = new Quote();

        // Resolve client (existing or create new unpersisted one)
        $client = $this->resolveClient($dto);
        $quote->setClient($client);

        // Map DTO fields to entity
        $quote->setQuoteId($dto->quoteId);
        $quote->setDue($dto->due);
        $quote->setDiscount($dto->discount);
        $quote->setTerms($dto->terms);
        $quote->setNotes($dto->notes);
        $quote->setTotal($dto->total);
        $quote->setBaseTotal($dto->baseTotal);
        $quote->setTax($dto->tax);

        // Sync lines collection
        foreach ($dto->lines as $line) {
            $quote->addLine($line);
        }

        // Sync invoice-level taxes (withholding / surcharges / informational)
        foreach ($dto->invoiceTaxes as $invoiceTax) {
            $this->ensureInvoiceTaxSnapshot($invoiceTax);
            $quote->addInvoiceTax($invoiceTax);
        }

        // Sync users collection
        // If new client mode, add the newly created contact from the client
        if ($dto->clientMode === QuoteClientMode::NewClient) {
            foreach ($client->getContacts() as $contact) {
                $quote->addUser($contact);
            }
        } else {
            // Existing client mode - use selected contacts from DTO
            foreach ($dto->users as $user) {
                $quote->addUser($user);
            }
        }

        return $quote;
    }

    /**
     * Updates an existing Quote from DTO
     * Does NOT change client (edit mode keeps existing client)
     */
    public function updateQuoteFromDTO(Quote $quote, QuoteFormDTO $dto): void
    {
        $quote->setQuoteId($dto->quoteId);
        $quote->setDue($dto->due);
        $quote->setDiscount($dto->discount);
        $quote->setTerms($dto->terms);
        $quote->setNotes($dto->notes);
        $quote->setTotal($dto->total);
        $quote->setBaseTotal($dto->baseTotal);
        $quote->setTax($dto->tax);

        // Sync lines collection
        $quote->getLines()->clear();
        foreach ($dto->lines as $line) {
            $quote->addLine($line);
        }

        // Sync invoice-level taxes
        foreach ($quote->getInvoiceTaxes() as $existing) {
            if (! $dto->invoiceTaxes->contains($existing)) {
                $quote->removeInvoiceTax($existing);
            }
        }

        foreach ($dto->invoiceTaxes as $invoiceTax) {
            $this->ensureInvoiceTaxSnapshot($invoiceTax);
            $quote->addInvoiceTax($invoiceTax);
        }

        // Sync users collection
        $quote->getUsers()->clear();
        foreach ($dto->users as $user) {
            $quote->addUser($user);
        }
    }

    private function ensureInvoiceTaxSnapshot(InvoiceTax $invoiceTax): void
    {
        if ($invoiceTax->getNameSnapshot() !== null && $invoiceTax->getNameSnapshot() !== '') {
            return;
        }

        $tax = $invoiceTax->getTax();
        if ($tax instanceof Tax) {
            $invoiceTax->snapshotFrom($tax);
        }
    }

    /**
     * Creates DTO from Quote entity for editing
     */
    public function createDTOFromQuote(Quote $quote): QuoteFormDTO
    {
        $dto = new QuoteFormDTO();

        // Always use Existing mode when editing
        $dto->clientMode = QuoteClientMode::Existing;
        $dto->client = $quote->getClient();

        // Map entity fields to DTO
        $dto->quoteId = $quote->getQuoteId();
        $dto->due = $quote->getDue();
        $dto->discount = $quote->getDiscount();
        $dto->terms = $quote->getTerms();
        $dto->notes = $quote->getNotes();
        $dto->total = (string) $quote->getTotal();
        $dto->baseTotal = (string) $quote->getBaseTotal();
        $dto->tax = (string) $quote->getTax();

        // Copy collections
        foreach ($quote->getLines() as $line) {
            $dto->lines->add($line);
        }

        foreach ($quote->getUsers() as $user) {
            $dto->users->add($user);
        }

        foreach ($quote->getInvoiceTaxes() as $invoiceTax) {
            $dto->invoiceTaxes->add($invoiceTax);
        }

        return $dto;
    }

    /**
     * Resolves client from DTO - returns existing client or creates new unpersisted one
     */
    private function resolveClient(QuoteFormDTO $dto): Client
    {
        if ($dto->clientMode === QuoteClientMode::Existing) {
            if (! $dto->client instanceof Client) {
                throw new InvalidArgumentException('Client is required when clientMode is Existing');
            }

            return $dto->client;
        }

        // Mode is New - create unpersisted client
        return $this->createClientFromDTO($dto);
    }

    /**
     * Creates an unpersisted Client entity from inline DTO fields
     * Client will be persisted later via cascade when quote is saved
     */
    private function createClientFromDTO(QuoteFormDTO $dto): Client
    {
        if (! $dto->hasInlineClientData()) {
            throw new InvalidArgumentException('Inline client data is incomplete');
        }

        $client = new Client();
        $client->setName($dto->newClientName);
        $client->setIsCompany($dto->newClientIsCompany);

        if ($dto->newClientIsCompany) {
            $client->setSiret($dto->newClientSiret);
        }

        // Set currency from system config
        $currency = $this->systemConfig->getCurrency();
        $client->setCurrency($currency);

        // Create contact and associate with client
        $contact = new Contact();
        $contact->setFirstName($dto->newContactFirstName);
        $contact->setLastName($dto->newContactLastName);
        $contact->setEmail($dto->newContactEmail);
        $contact->setClient($client);

        // Add contact to client (cascade will persist)
        $client->addContact($contact);

        // Return unpersisted client - will be saved via cascade
        return $client;
    }
}
