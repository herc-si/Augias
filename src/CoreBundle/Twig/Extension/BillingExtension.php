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

use Augias\CoreBundle\Entity\Discount;
use Augias\CoreBundle\Form\FieldRenderer;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\MoneyBundle\Calculator;
use Augias\QuoteBundle\Entity\Quote;
use Brick\Math\BigNumber;
use NumberFormatter;
use Override;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function sprintf;

class BillingExtension extends AbstractExtension
{
    public function __construct(
        private readonly FieldRenderer $fieldRenderer,
        private readonly Calculator $calculator,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('billing_fields', fn (FormView $form) => $this->fieldRenderer->render($form, 'children[lines].vars[prototype]'), ['is_safe' => ['html']]),
            new TwigFunction('discount', fn ($entity): BigNumber => $this->calculator->calculateDiscount($entity)),
            new TwigFunction('discount_label', $this->discountLabel(...)),
        ];
    }

    /**
     * "Remise (10 %)": the rate as well as the amount, when the discount is
     * one — the documents showed the amount alone ("je vois la remise mais
     * pas le % mis", 06/10/2026).
     */
    public function discountLabel(Invoice | Quote $document, string $domain = 'messages'): string
    {
        $label = $document instanceof Quote ? 'quote.discount' : 'invoice.discount';
        $label = null === $this->translator ? $label : $this->translator->trans($label, [], $domain);
        $discount = $document->getDiscount();

        if (Discount::TYPE_PERCENTAGE !== $discount->getType() || ! $discount->getValuePercentage()) {
            return $label;
        }

        $locale = $this->translator?->getLocale() ?? 'en';
        $rate = new NumberFormatter($locale, NumberFormatter::PERCENT);
        $rate->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return sprintf('%s (%s)', $label, $rate->format($discount->getValuePercentage() / 100));
    }
}
