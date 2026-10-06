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

        // In words, agreeing with the number ("affiche le mot complet", 06/10/2026).
        self::assertSame("3\u{00A0}heures", $extension->lineQuantity(new Line()->setQty(3)->setUnit(QuantityUnit::Hour)));
        self::assertSame("1\u{00A0}heure", $extension->lineQuantity(new Line()->setQty(1)->setUnit(QuantityUnit::Hour)));
        self::assertSame("1,5\u{00A0}heure", $extension->lineQuantity(new Line()->setQty('1.5')->setUnit(QuantityUnit::Hour)));
        self::assertSame("2\u{00A0}jours", $extension->lineQuantity(new QuoteLine()->setQty(2)->setUnit(QuantityUnit::Day)));
        self::assertSame("1\u{00A0}forfait", $extension->lineQuantity(new Line()->setQty(1)->setUnit(QuantityUnit::FlatRate)));
        self::assertSame("2\u{00A0}forfaits", $extension->lineQuantity(new Line()->setQty(2)->setUnit(QuantityUnit::FlatRate)));
        self::assertSame("3\u{00A0}mois", $extension->lineQuantity(new Line()->setQty(3)->setUnit(QuantityUnit::Month)));
    }

    public function testAPlainCountReadsAsBefore(): void
    {
        $extension = new LineQuantityExtension(self::getContainer()->get(TranslatorInterface::class));
        $line = new Line()->setQty(3);

        self::assertSame((string) $line->getQty(), $extension->lineQuantity($line));
    }
}
