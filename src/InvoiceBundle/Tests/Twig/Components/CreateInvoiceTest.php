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

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\InvoiceBundle\DTO\InvoiceFormDTO;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Manager\InvoiceFormManager;
use Augias\InvoiceBundle\Model\Graph;
use Augias\InvoiceBundle\Twig\Components\CreateInvoice;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Workflow\WorkflowInterface;

final class CreateInvoiceTest extends LiveComponentTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // The snapshots below are about the rendered component, not about the
        // shipped numbering defaults — and one of those defaults is the current
        // year, which would put an expiry date on every snapshot. The affixes
        // are cleared so the generated id stays a bare number here; what the
        // defaults produce is covered by
        // {@see \Augias\InvoiceBundle\Tests\Config\ConfigProviderTest}.
        $config = self::getContainer()->get(SystemConfig::class);
        $config->set('invoice/id_generation/id_prefix', '');
        $config->set('invoice/id_generation/id_suffix', '');
    }

    public function testCreateInvoice(): void
    {
        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto]
        )->actingAs($this->getUser());

        $this->assertMatchesHtmlSnapshot($this->replaceChecksum($component->render()->toString()));
    }

    /**
     * The catalogue stores prices in minor units and the money field reads
     * major units, so handing the stored integer straight to the form value
     * multiplied every catalogue line by the currency's factor — 700 EUR came
     * out as 70 000.
     */
    public function testAddFromCatalogFillsThePriceInMajorUnits(): void
    {
        $product = new Product()
            ->setName('Journée de développement')
            ->setSalePrice(BigInteger::of(70_000))
            ->setType(ProductType::Service)
            ->setUnit(QuantityUnit::Day);
        $product->setCompany($this->company);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->persist($product);
        $entityManager->flush();

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => new InvoiceFormDTO()],
        )->actingAs($this->getUser());

        $component->set('catalogProductId', (string) $product->getId());
        $component->call('addFromCatalog');

        $formValues = $component->component()->formValues;

        // Compared numerically: the field re-renders the value through its own
        // formatting, so "700.00" and "700" are both correct answers and only
        // the magnitude is being asserted here.
        self::assertSame(700.0, (float) $formValues['lines'][0]['price']);
        // Sold by the day in the catalogue, so counted in days on the line.
        self::assertSame('day', $formValues['lines'][0]['unit']);
    }

    /**
     * @throws MathException
     */
    public function testCreateInvoiceWithMultipleLines(): void
    {
        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');
        $dto->lines->add(new Line()->setPrice(10000)->setQty(1));
        $dto->lines->add(new Line()->setPrice(10000)->setQty(1));

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto]
        )->actingAs($this->getUser());

        $this->assertMatchesHtmlSnapshot($this->replaceChecksum($component->render()->toString()));
    }

    /**
     * @throws MathException
     */
    public function testCreateInvoiceWithTaxRates(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $tax = new Tax()
            ->setName('VAT')
            ->setRate(20)
            ->setType(Tax::TYPE_INCLUSIVE);

        $em->persist($tax);

        $em->flush();

        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');
        $dto->lines->add(new Line()->setPrice(10000)->setQty(1));

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto]
        )->actingAs($this->getUser());

        $this->assertMatchesHtmlSnapshot($this->replaceChecksum($component->render()->toString()));
    }

    /**
     * Tests that contacts are auto-selected when a client is pre-selected.
     * The component's PostMount hook should auto-select all contacts.
     *
     * @throws MathException
     */
    public function testCreateInvoiceWithPreselectedClientAutoSelectsContacts(): void
    {
        $client = ClientFactory::createOne([
            'name' => 'Test Client',
            'currencyCode' => 'USD',
        ]);

        ContactFactory::createOne([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'client' => $client,
        ]);

        ContactFactory::createOne([
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'client' => $client,
        ]);

        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');
        $dto->client = $client;
        $dto->lines->add(new Line()->setPrice(10000)->setQty(1));

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto]
        )->actingAs($this->getUser());

        $rendered = $component->render()
            ->toString();

        // Verify both contacts are displayed
        self::assertStringContainsString('John Doe', $rendered);
        self::assertStringContainsString('Jane Smith', $rendered);

        // Verify checkboxes are checked (contacts are selected by PostMount hook)
        self::assertStringContainsString('checked', $rendered);

        $this->assertMatchesHtmlSnapshot($this->replaceChecksum($this->replaceUuid($rendered)));
    }

    /**
     * Regression test for issue #2347.
     *
     * When editing a Pending invoice (accept transition already applied) and clicking
     * "Save & Send", the component must not throw NotEnabledTransitionException.
     * The workflow guard introduced in CreateInvoice::saveInvoice() skips the
     * accept transition when it is no longer enabled.
     */
    public function testSaveSendDoesNotThrowWhenInvoiceAlreadyPending(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();

        $client = ClientFactory::createOne([
            'name' => 'Acme Corp',
            'currencyCode' => 'USD',
        ]);

        $contact = ContactFactory::createOne([
            'firstName' => 'Alice',
            'lastName' => 'Smith',
            'email' => 'alice@example.com',
            'client' => $client,
        ]);

        // Build a persisted Pending invoice (accept transition already applied).
        $line = new Line()
            ->setDescription('Consulting')
            ->setPrice(10000)
            ->setQty(1);

        $invoice = new Invoice();
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setClient($client);
        $invoice->setInvoiceId('INV-TEST-001');
        $invoice->setInvoiceDate(CarbonImmutable::parse('2024-01-15'));
        $invoice->addUser($contact);
        $invoice->addLine($line);

        $em->persist($invoice);
        $em->flush();

        // Verify the accept transition is indeed unavailable for a Pending invoice.
        /** @var WorkflowInterface $stateMachine */
        $stateMachine = self::getContainer()->get('state_machine.invoice');
        self::assertFalse(
            $stateMachine->can($invoice, Graph::TRANSITION_ACCEPT),
            'The accept transition must not be available for a Pending invoice.',
        );

        // Build the DTO from the existing invoice (simulates the edit page load).
        /** @var InvoiceFormManager $formManager */
        $formManager = self::getContainer()->get(InvoiceFormManager::class);
        $dto = $formManager->createDTOFromInvoice($invoice);

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: [
                'dto' => $dto,
                'isEdit' => true,
                'invoice' => $invoice,
            ],
            client: $this->client,
        )->actingAs($this->getUser());

        // Before the fix this threw NotEnabledTransitionException.
        $component->call('saveSend');

        // The invoice status must remain Pending — no re-accept attempted.
        $em = self::getContainer()->get('doctrine')->getManager();
        $refreshedInvoice = $em->find(Invoice::class, $invoice->getId());
        self::assertNotNull($refreshedInvoice);
        self::assertSame(InvoiceStatus::Pending, $refreshedInvoice->getStatus());

        // The action must have produced a redirect (not a 422 / exception page).
        $response = $this->client->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * Tests that the component correctly tracks previous client ID.
     * The PostMount hook should set previousClientId when auto-selecting contacts.
     */
    public function testPreviousClientIdIsTracked(): void
    {
        $client = ClientFactory::createOne([
            'name' => 'Test Client',
            'currencyCode' => 'USD',
        ]);

        ContactFactory::createOne([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'client' => $client,
        ]);

        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');
        $dto->client = $client;

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto]
        )->actingAs($this->getUser());

        // Render the component
        $component->render();

        // Access the component instance to verify previousClientId is set
        $componentInstance = $component->component();

        self::assertInstanceOf(CreateInvoice::class, $componentInstance);
        self::assertSame((string) $client->getId(), $componentInstance->previousClientId);
    }

    /**
     * The whole chain a percentage discount travels: the select's value reaches
     * the DTO's Discount, is stored as the percentage itself, and is applied to
     * the tax-inclusive total.
     *
     * 100.00 x 2 = 200.00, so 15% off is 30.00 and the total 170.00. Two steps
     * of that used to return zero on their own: a null discount on the DTO
     * published an empty type in the live props, and Discount::setValue()
     * dropped the amount for any type it did not recognise.
     *
     * Asserted on the rendered totals rather than on the component's own DTO:
     * component() hands back an instance hydrated from the response props, and
     * the DTO is not a LiveProp, so its discount there is a fresh one that was
     * never bound to the submitted form.
     */
    public function testAPercentageDiscountSubmittedThroughTheFormIsApplied(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'USD']);

        $contact = ContactFactory::createOne([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'client' => $client,
        ]);

        $dto = new InvoiceFormDTO();
        $dto->invoiceDate = CarbonImmutable::parse('2021-01-01');

        $component = $this->createLiveComponent(
            name: CreateInvoice::class,
            data: ['dto' => $dto],
            client: $this->client,
        )->actingAs($this->getUser());

        $component->render();

        $component->submitForm([
            'invoice' => [
                'clientMode' => 'existing',
                'client' => (string) $client->getId(),
                'users' => [(string) $contact->getId()],
                'invoiceId' => 'INV-DISCOUNT-001',
                'invoiceDate' => '2021-01-01',
                'lines' => [['description' => 'Consulting', 'price' => '100', 'qty' => '2']],
                'discount' => ['type' => 'percentage', 'value' => '15'],
                'total' => '0',
                'baseTotal' => '0',
                'tax' => '0',
                'terms' => '',
                'notes' => '',
            ],
        ]);

        $componentInstance = $component->component();
        self::assertInstanceOf(CreateInvoice::class, $componentInstance);

        // Stored and read back as typed, with no scaling in between.
        self::assertSame(
            ['type' => Discount::TYPE_PERCENTAGE, 'value' => '15'],
            $componentInstance->formValues['discount'],
        );

        preg_match_all('#totals-value">([^<]*)<#', $component->render()->toString(), $totals);

        self::assertSame(['$200.00', '$30.00', '$170.00'], $totals[1]);
    }
}
