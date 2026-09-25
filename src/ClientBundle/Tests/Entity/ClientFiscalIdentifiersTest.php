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

namespace Augias\ClientBundle\Tests\Entity;

use Augias\ClientBundle\Entity\Client;
use Augias\TaxBundle\Entity\TaxIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_map;

/**
 * SIRET, SIREN and VAT number have fields of their own on the form, and are
 * still stored as identifiers — where everything else reads them.
 */
#[CoversClass(Client::class)]
final class ClientFiscalIdentifiersTest extends TestCase
{
    public function testAFieldCreatesChangesAndRemovesItsIdentifier(): void
    {
        $client = new Client();

        $client->setSiret('12345678900012');
        self::assertSame('12345678900012', $client->getSiret());
        self::assertCount(1, $client->getTaxIdentifiers());
        self::assertSame('SIRET', $client->getTaxIdentifiers()->first()->getLabel());

        $client->setSiret(' 98765432100019 ');
        self::assertSame('98765432100019', $client->getSiret());
        self::assertCount(1, $client->getTaxIdentifiers(), 'Changed, not added twice.');

        $client->setSiret('');
        self::assertNull($client->getSiret());
        self::assertCount(0, $client->getTaxIdentifiers());
    }

    /**
     * The list on the form shows the rest — a SIRET entered in a field must
     * not turn up there as well.
     */
    public function testTheOtherIdentifiersLeaveTheThreeOut(): void
    {
        $client = new Client();
        $client->setSiret('12345678900012');
        $client->setVatNumber('FR12345678901');
        $client->addOtherTaxIdentifier(new TaxIdentifier()->setLabel('RCS')->setValue('RCS Paris 123'));

        self::assertSame(['RCS'], array_map(
            static fn (TaxIdentifier $identifier): ?string => $identifier->getLabel(),
            $client->getOtherTaxIdentifiers(),
        ));
        self::assertCount(3, $client->getTaxIdentifiers());
    }
}
