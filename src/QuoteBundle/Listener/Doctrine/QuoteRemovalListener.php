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

namespace Augias\QuoteBundle\Listener\Doctrine;

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Deleting a quote leaves the invoice it became, and only the link goes.
 *
 * The column is declared ON DELETE SET NULL, but SQLite only honours foreign
 * keys when told to on each connection, and nothing tells it. The invoice kept
 * pointing at a quote that was no longer there, and its page failed to load.
 * Said here, it holds whatever the database does.
 *
 * @see \Augias\QuoteBundle\Tests\Listener\Doctrine\QuoteRemovalListenerTest
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Quote::class)]
final class QuoteRemovalListener
{
    public function preRemove(Quote $quote): void
    {
        $invoice = $quote->getInvoice();

        if ($invoice instanceof Invoice && $invoice->getQuote() === $quote) {
            $invoice->detachQuote();
        }
    }
}
