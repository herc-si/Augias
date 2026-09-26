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

namespace Augias\CoreBundle\Contracts;

use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Company;

/**
 * Whether a company must keep its books in the application before the
 * payments of private customers are recorded.
 *
 * An application that records payments outside the books is a cash register
 * in the eyes of the tax administration (CGI, art. 286, I, 3° bis;
 * BOI-TVA-DECLA-30-10-30 § 30 and 40), whatever it calls itself, and must be
 * certified — unless each payment "obligatoirement, instantanément et
 * automatiquement" makes an entry in the books, which is what Augias does
 * once they are kept. The obligation reaches only VAT-registered companies
 * selling to private customers: franchise en base, and business-only sales,
 * are outside it.
 */
interface CashRegisterGateInterface
{
    /**
     * VAT-registered, and not keeping the book the payments go to. Null reads
     * the company being worked in.
     */
    public function requiresBooks(?Company $company = null): bool;

    /**
     * Whether recording this customer's payment by hand must be refused: a
     * private customer, in a company that {@see self::requiresBooks()}.
     */
    public function refusesPaymentFrom(Company $company, ?Client $client): bool;
}
