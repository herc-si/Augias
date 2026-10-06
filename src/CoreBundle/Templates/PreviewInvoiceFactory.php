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

namespace Augias\CoreBundle\Templates;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\SettingsBundle\SystemConfig;
use Carbon\CarbonImmutable;
use ReflectionProperty;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function sprintf;

/**
 * Builds a purely in-memory invoice with plausible sample data so template
 * previews can render every design without touching the database. The company
 * details (name, logo, address) still come from the active company via the
 * usual Twig helpers, so the preview looks like the user's own invoice.
 *
 * The sample itself speaks the user's language and carries units — a flat
 * rate, days, hours — so the quantity column shows what it will on a real
 * invoice instead of bare counts.
 *
 * @see \Augias\CoreBundle\Tests\Functional\TemplatePreviewActionTest
 */
final readonly class PreviewInvoiceFactory
{
    public function __construct(
        private SystemConfig $systemConfig,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(): Invoice
    {
        $client = new Client();
        $client->setName($this->sample('client'));
        $client->setCurrencyCode($this->systemConfig->getCurrency()->getCode());

        $contact = new Contact();
        $contact->setFirstName($this->sample('contact_first_name'));
        $contact->setLastName($this->sample('contact_last_name'));
        $contact->setEmail('contact@example.com');

        $client->addContact($contact);

        $address = new Address();
        $address->setStreet1($this->sample('street'));
        $address->setCity($this->sample('city'));
        $address->setZip($this->sample('zip'));
        $address->setCountry($this->sample('country'));

        $client->addAddress($address);

        $invoice = new Invoice();
        $invoice->setInvoiceId(sprintf('%s-%s-0042', $this->sample('invoice_prefix'), CarbonImmutable::now()->format('Y')));
        $invoice->setUuid(Uuid::v4());
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setClient($client);
        $invoice->setInvoiceDate(CarbonImmutable::parse('first day of this month'));
        $invoice->setDue(CarbonImmutable::now()->addDays(14));
        $invoice->setTerms($this->sample('terms'));
        // A discount, so that choosing a design shows where it puts one.
        $invoice->setDiscount(new Discount()->setType(Discount::TYPE_PERCENTAGE)->setValuePercentage(10.0));

        foreach ([
            ['line_identity', 120000, 1, QuantityUnit::FlatRate],
            ['line_website', 42500, 2, QuantityUnit::Day],
            ['line_support', 15000, 3, QuantityUnit::Hour],
        ] as [$description, $price, $qty, $unit]) {
            $line = new Line();
            $line->setDescription($this->sample($description));
            $line->setPrice($price);
            $line->setQty($qty);
            $line->setUnit($unit);
            $line->setTotal((int) ($price * $qty));

            $invoice->addLine($line);
        }

        $invoice->setBaseTotal(250000);
        $invoice->setTax(0);
        $invoice->setTotal(225000);
        $invoice->setBalance(225000);

        // Some render paths (e.g. the custom-fields component) require a
        // non-null id; the invoice is never persisted so any Ulid will do.
        new ReflectionProperty(Invoice::class, 'id')->setValue($invoice, new Ulid());

        return $invoice;
    }

    private function sample(string $key): string
    {
        return $this->translator->trans('saas.settings.template_preview.sample.' . $key);
    }
}
