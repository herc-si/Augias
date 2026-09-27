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
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use function is_array;
use function method_exists;

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

        $quantity = method_exists($line, 'getQty') ? (string) $line->getQty() : '';
        $unit = method_exists($line, 'getUnit') ? $line->getUnit() : null;
        $short = $unit instanceof QuantityUnit ? $unit->shortLabel() : null;

        return null === $short ? $quantity : $quantity . ' ' . $this->translator->trans($short);
    }
}
