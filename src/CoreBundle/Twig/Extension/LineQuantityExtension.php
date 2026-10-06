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

namespace Augias\CoreBundle\Twig\Extension;

use Augias\CoreBundle\Enum\QuantityUnit;
use NumberFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use function is_array;
use function is_numeric;
use function method_exists;
use function str_replace;

/**
 * `line|line_quantity`: a document line's quantity with what it counts —
 * "3 h", "2 j", "1 forfait" — and a plain count as it always read, "3".
 *
 * The number is written exactly as before; only the unit is added.
 *
 * @see \Augias\CoreBundle\Tests\Twig\Extension\LineQuantityExtensionTest
 */
final readonly class LineQuantityExtension
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param object|array<string, mixed> $line
     */
    #[AsTwigFilter('line_quantity')]
    public function lineQuantity(object | array $line): string
    {
        if (is_array($line)) {
            return (string) ($line['qty'] ?? '');
        }

        // A BigNumber on the entities: its string is what it always printed.
        $quantity = method_exists($line, 'getQty') ? (string) $line->getQty() : '';
        $unit = method_exists($line, 'getUnit') ? $line->getUnit() : null;
        $label = $unit instanceof QuantityUnit ? $unit->quantityLabel() : null;

        if (null === $label || ! is_numeric($quantity)) {
            return $quantity;
        }

        // "1,5 heure": the number as the reader writes it, the word agreeing with it.
        $number = new NumberFormatter($this->translator->getLocale(), NumberFormatter::DECIMAL);
        $number->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 3);

        // Bound by a no-break space: in a narrow column, "12,5" and "heures"
        // were set on two lines.
        return str_replace(' ', "\u{00A0}", $this->translator->trans($label, ['%count%' => (float) $quantity, '%quantity%' => $number->format((float) $quantity)]));
    }
}
