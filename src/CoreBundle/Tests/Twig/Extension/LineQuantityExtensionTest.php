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

namespace Augias\CoreBundle\Tests\Twig\Extension;

use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Twig\Extension\LineQuantityExtension;
use Augias\InvoiceBundle\Entity\Line;
use Augias\QuoteBundle\Entity\Line as QuoteLine;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "3 h" on the document rather than a bare "3" — and a plain count exactly
 * as it always read.
 */
#[CoversClass(LineQuantityExtension::class)]
final class LineQuantityExtensionTest extends KernelTestCase
{
    public function testTheUnitFollowsTheQuantity(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $translator->setLocale('fr_FR');
        $extension = new LineQuantityExtension($translator);

        self::assertSame('3 h', $extension->lineQuantity(new Line()->setQty(3)->setUnit(QuantityUnit::Hour)));
        self::assertSame('2 j', $extension->lineQuantity(new QuoteLine()->setQty(2)->setUnit(QuantityUnit::Day)));
        self::assertSame('1 forfait', $extension->lineQuantity(new Line()->setQty(1)->setUnit(QuantityUnit::FlatRate)));
    }

    public function testAPlainCountReadsAsBefore(): void
    {
        $extension = new LineQuantityExtension(self::getContainer()->get(TranslatorInterface::class));
        $line = new Line()->setQty(3);

        self::assertSame((string) $line->getQty(), $extension->lineQuantity($line));
    }
}
