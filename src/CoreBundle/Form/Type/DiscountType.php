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

namespace Augias\CoreBundle\Form\Type;

use Augias\CoreBundle\Entity\Discount;
use Augias\SettingsBundle\SystemConfig;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Money\Currency;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use function is_array;
use function is_float;

/**
 * @see \Augias\CoreBundle\Tests\Form\Type\DiscountTypeTest
 * @extends AbstractType<Discount>
 */
class DiscountType extends AbstractType
{
    private const array DISCOUNT_TYPES = [
        'percentage' => [
            'symbol' => '%',
            'name' => 'percentage',
        ],
        'money' => [
            'symbol' => '',
            'name' => 'money',
        ],
    ];

    public function __construct(
        private readonly SystemConfig $systemConfig
    ) {
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['types'] = self::DISCOUNT_TYPES;
        $view->vars['currency'] = $options['currency']->getCode();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'type',
            ChoiceType::class,
            [
                'label' => 'form.field.type',
                'attr' => [
                    'class' => 'discount-type'
                ],
                'choices' => [
                    '%' => 'percentage',
                    $options['currency']->getCode() => 'money',
                ],
            ]
        );

        $builder->add(
            'value',
            TextType::class,
            [
                'label' => 'form.field.value',
                // An emptied field is no discount. Without this it maps null
                // onto Discount::setValue(), which does not take one: a 500 the
                // moment the credit note form adds this field blank.
                'empty_data' => '0',
                'attr' => [
                    'class' => 'discount-value',
                ],
            ]
        );

        // The single `value` field holds two different things depending on the
        // type next to it: a money discount is stored in minor units, a
        // percentage is stored as the percentage itself — 15 means 15%, which
        // is what the API and the MCP tools have always written and read.
        //
        // Only the money side needs converting, and which side applies is only
        // known per submission, so it is done here rather than by a view
        // transformer on `value` alone: that transformer scaled both, filing a
        // 15% discount as 1500 and leaving Calculator to guess from the
        // magnitude which of the two conventions a stored figure followed.
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            static function (FormEvent $event): void {
                $discount = $event->getData();

                if (! $discount instanceof Discount || Discount::TYPE_MONEY !== $discount->getType()) {
                    return;
                }

                $event->getForm()
                    ->get('value')
                    ->setData(
                        (string) $discount->getValueMoney()
                            ->toBigDecimal()
                            ->dividedBy(100, 2, RoundingMode::HalfEven)
                    );
            }
        );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            static function (FormEvent $event): void {
                $data = $event->getData();

                if (! is_array($data) || Discount::TYPE_MONEY !== ($data['type'] ?? null)) {
                    return;
                }

                $value = $data['value'] ?? null;

                if ($value === null || $value === '') {
                    return;
                }

                $data['value'] = (string) BigNumber::of(is_float($value) ? (string) $value : $value)
                    ->toBigDecimal()
                    ->multipliedBy(100);

                $event->setData($data);
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', Discount::class);
        $resolver->setDefault('currency', $this->systemConfig->getCurrency());
        $resolver->setAllowedTypes('currency', [Currency::class]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'discount';
    }
}
