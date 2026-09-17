<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\BillBundle\Form\Type;

use Augias\BillBundle\Entity\Bill;
use Augias\ClientBundle\Entity\Client;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityRepository;
use Money\Currency;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @see \Augias\BillBundle\Tests\Form\Type\BillTypeTest
 * @extends AbstractType<Bill>
 */
final class BillType extends AbstractType
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $bill = $options['data'] ?? null;
        $existingSupplier = $bill instanceof Bill && $bill->hasSupplier() ? $bill->getSupplier() : null;

        $builder
            // Unmapped: a brand new supplier can be typed into `newSupplierName`
            // below instead of picked here, so the Action resolves the two into
            // a single `Client` and calls `setSupplier()` itself rather than
            // letting the form's automatic property mapping do it — mapping
            // would otherwise try `setSupplier(null)` (a TypeError, the
            // property isn't nullable) whenever the "new supplier" path is used.
            ->add('supplier', EntityType::class, [
                'class' => Client::class,
                'label' => 'bill.form.supplier.label',
                'choice_label' => 'name',
                'required' => false,
                'mapped' => false,
                'data' => $existingSupplier,
                'placeholder' => 'bill.form.supplier.placeholder',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('c')
                    ->andWhere('c.isSupplier = true'),
            ])
            ->add('newSupplierName', TextType::class, [
                'label' => 'bill.form.new_supplier_name.label',
                'help' => 'bill.form.new_supplier_name.help',
                'required' => false,
                'mapped' => false,
            ])
            ->add('billNumber', null, ['label' => 'bill.form.bill_number.label', 'required' => false])
            ->add('issueDate', DateType::class, [
                'label' => 'bill.form.issue_date.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'bill.form.due_date.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('currencyCode', CurrencyType::class, ['label' => 'bill.form.currency.label'])
            ->add('totalAmount', MoneyType::class, [
                'label' => 'bill.form.total_amount.label',
                'currency' => $options['currency'],
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'label' => 'bill.form.category.label',
                'required' => false,
                'placeholder' => 'bill.form.category.placeholder',
                'choice_label' => 'name',
                // Categories are one shared list now, so this has to ask for
                // the purchase side only — otherwise the dropdown would offer
                // the things you sell.
                'query_builder' => static fn (CategoryRepository $repository) => $repository->forUsage(CategoryUsage::Purchase),
            ])
            ->add('notes', TextareaType::class, ['label' => 'bill.form.notes.label', 'required' => false]);

        // Only for a company that is in the scope of VAT. One in franchise en
        // base deducts nothing, and would be asked for a figure it can do
        // nothing with. Read from the shared setting rather than from the
        // accounting profile: bills are kept whether or not the accounting
        // module is configured.
        if (! $this->systemConfig->isVatExempt()) {
            $builder->add('taxAmount', MoneyType::class, [
                'label' => 'bill.form.tax_amount.label',
                'help' => 'bill.form.tax_amount.help',
                'currency' => $options['currency'],
                'required' => false,
            ]);
        }

        // `supplier` was made optional so a brand new supplier can be typed
        // into `newSupplierName` instead of picked from the list (handled by
        // the Action once the form is valid) — but exactly one of the two is
        // required, which plain field-level constraints can't express.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if ($form->get('supplier')->getData() === null && trim((string) $form->get('newSupplierName')->getData()) === '') {
                // Translated here, not left as a key: the form theme puts a
                // constraint violation through the translator on its way to the
                // page, but not a FormError added by hand — so the key was what
                // the user read.
                $form->get('supplier')->addError(new FormError(
                    $this->translator->trans('bill.constraint.supplier_required', [], 'validators'),
                ));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Bill::class,
            // The company's currency, not a hardcoded EUR: the money field
            // scales by the currency's own decimal count, so a wrong one
            // silently misplaces the decimal point for JPY and BHD.
            'currency' => $this->systemConfig->getCurrency(),
        ]);

        $resolver->setAllowedTypes('currency', Currency::class);
    }

    public function getBlockPrefix(): string
    {
        return 'bill';
    }
}
