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

namespace Augias\McpBundle\Mcp\Tool;

use Augias\CatalogBundle\Entity\Product;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\PaymentBundle\Entity\Payment;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\QuoteBundle\Entity\Quote;
use Augias\TaxBundle\Entity\Tax;
use Mcp\Exception\ToolCallException;

/**
 * Maps short resource identifiers used by MCP tools to their Doctrine entity
 * classes. Tools pass a name like "invoice" or "client"; this service resolves
 * the class, shielding tool callers from internal namespaces.
 */
final class ResourceRegistry
{
    /**
     * @var array<string, class-string>
     */
    private const array RESOURCES = [
        'invoice' => Invoice::class,
        'credit_note' => CreditNote::class,
        'product' => Product::class,
        'recurring_invoice' => RecurringInvoice::class,
        'quote' => Quote::class,
        'client' => Client::class,
        'contact' => Contact::class,
        'payment' => Payment::class,
        'payment_method' => PaymentMethod::class,
        'tax' => Tax::class,
    ];

    /**
     * @return class-string
     *
     * @throws ToolCallException
     */
    public function resolve(string $resource): string
    {
        if (! isset(self::RESOURCES[$resource])) {
            throw new ToolCallException(sprintf(
                'Unknown resource "%s". Supported resources: %s.',
                $resource,
                implode(', ', array_keys(self::RESOURCES)),
            ));
        }

        return self::RESOURCES[$resource];
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys(self::RESOURCES);
    }
}
