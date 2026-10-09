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

namespace Augias\InvoiceBundle\Tests\Twig\Components;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\InvoiceBundle\Twig\Components\CreateCreditNote;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CreateCreditNote::class)]
final class CreateCreditNoteTest extends LiveComponentTest
{
    public function testRendersTheForm(): void
    {
        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => $this->dto()])
            ->actingAs($this->getUser());

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    /**
     * A live re-render builds the component again from its LiveProps alone. The
     * DTO is not one of them — it is rebuilt from the submitted form values —
     * so it has to survive being absent, or the first interaction on the form
     * dies with "must not be accessed before initialization".
     */
    public function testSurvivesARerender(): void
    {
        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => $this->dto()])
            ->actingAs($this->getUser());

        $component->refresh();

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    public function testSurvivesAddingALine(): void
    {
        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => $this->dto()])
            ->actingAs($this->getUser());

        $component->call('addCollectionItem', ['name' => 'lines']);

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    /**
     * The contact checkboxes only exist once a client has been picked — the
     * field is dependent on it. That is also the moment the client card starts
     * rendering them, so anything else that renders the same field blows up
     * with "Field users has already been rendered".
     */
    public function testSurvivesPickingAClient(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => new CreditNoteFormDTO()])
            ->actingAs($this->getUser());

        $component->submitForm(['client' => (string) $client->getId()]);

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    /**
     * A credit that answers to no invoice has nothing to take a discount off:
     * the amount to give back is typed directly, and a discount on top of it
     * would be a second reduction nobody asked for.
     */
    public function testOffersNoDiscountWithoutAnInvoiceToMirror(): void
    {
        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => $this->dto()])
            ->actingAs($this->getUser());

        self::assertStringNotContainsString('credit_note[discount]', $component->render()->toString());
    }

    /**
     * The counterpart: mirroring an invoice that had a discount, the credit note
     * says it carries that discount over. It is shown, not typed.
     */
    public function testShowsTheInvoiceDiscountWhenMirroringAnInvoice(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $discount = new Discount();
        $discount->setType(Discount::TYPE_PERCENTAGE);
        $discount->setValue(10);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending, 'discount' => $discount]);

        $dto = new CreditNoteFormDTO();
        $dto->client = $client;
        $dto->creditedInvoice = $invoice;
        $dto->creditNoteDate = CarbonImmutable::now();
        $dto->lines->add(new CreditNoteLine());

        $html = $this->createLiveComponent(CreateCreditNote::class, ['dto' => $dto])
            ->actingAs($this->getUser())
            ->render()
            ->toString();

        self::assertStringContainsString('credit-note-invoice-discount', $html);
        self::assertStringNotContainsString('credit_note[discount]', $html);
    }

    /**
     * The gesture behind a 500 seen on app-test: a blank credit note, a client,
     * then an invoice. Picking the invoice adds the discount field blank, and
     * the next re-render submitted its empty value.
     */
    public function testSurvivesPickingAnInvoiceOnABlankCreditNote(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $invoice = InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending]);

        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => new CreditNoteFormDTO()])
            ->actingAs($this->getUser());

        $component->submitForm(['credit_note' => ['client' => (string) $client->getId()]]);
        $component->submitForm(['credit_note' => [
            'client' => (string) $client->getId(),
            'creditedInvoice' => (string) $invoice->getId(),
        ]]);
        $component->submitForm(['credit_note' => [
            'client' => (string) $client->getId(),
            'creditedInvoice' => (string) $invoice->getId(),
        ]]);

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    /**
     * The invoices a credit note can answer to are the issued ones, paid or
     * not: an issued invoice is corrected by a credit note, never withdrawn.
     * A draft was never issued, a cancelled one never owed.
     */
    public function testOffersOnlyIssuedInvoices(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $offered = [];
        foreach ([InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Paid] as $status) {
            $offered[] = (string) InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => $status])->getId();
        }

        $withheld = [];
        foreach ([InvoiceStatus::Draft, InvoiceStatus::Cancelled] as $status) {
            $withheld[] = (string) InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => $status])->getId();
        }

        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => new CreditNoteFormDTO()])
            ->actingAs($this->getUser());
        $component->submitForm(['credit_note' => ['client' => (string) $client->getId()]]);

        $html = $component->render()->toString();

        foreach ($offered as $id) {
            self::assertStringContainsString('value="' . $id . '"', $html);
        }

        foreach ($withheld as $id) {
            self::assertStringNotContainsString('value="' . $id . '"', $html);
        }
    }

    private function dto(): CreditNoteFormDTO
    {
        $dto = new CreditNoteFormDTO();
        // Pinned: a Faker currency moneyphp does not know makes the money
        // filter throw while the component renders.
        $dto->client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $dto->creditNoteDate = CarbonImmutable::now();
        $dto->lines->add(new CreditNoteLine());

        return $dto;
    }
}
