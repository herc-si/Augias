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
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
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
        $client = ClientFactory::createOne(['company' => $this->company]);

        $component = $this->createLiveComponent(CreateCreditNote::class, ['dto' => new CreditNoteFormDTO()])
            ->actingAs($this->getUser());

        $component->submitForm(['client' => (string) $client->getId()]);

        self::assertStringContainsString('credit_note', $component->render()->toString());
    }

    private function dto(): CreditNoteFormDTO
    {
        $dto = new CreditNoteFormDTO();
        $dto->client = ClientFactory::createOne(['company' => $this->company]);
        $dto->creditNoteDate = CarbonImmutable::now();
        $dto->lines->add(new CreditNoteLine());

        return $dto;
    }
}
