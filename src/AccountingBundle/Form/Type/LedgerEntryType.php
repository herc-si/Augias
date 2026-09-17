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

namespace Augias\AccountingBundle\Form\Type;

use Augias\AccountingBundle\Entity\LedgerEntry;
use Augias\AccountingBundle\Enum\ActivityNature;
use Augias\AccountingBundle\Enum\LedgerBook;
use Augias\AccountingBundle\Enum\SettlementMethod;
use Augias\AccountingBundle\Service\LedgerTaxSplitter;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\Tax;
use Augias\TaxBundle\Enum\TaxCategory;
use Augias\TaxBundle\Repository\TaxRepository;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Money\Currency;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use function sprintf;
use function trim;

/**
 * The form behind a hand-written book entry — money that moved without passing
 * through an invoice or a supplier bill, and corrections to what did.
 *
 * An entry the application wrote itself is shown here too, but only its
 * bookkeeping-side fields can be touched: the activity it counts towards, which
 * is a judgement the payment record cannot make, and the notes. Its date and
 * amount mirror a payment and would silently disagree with it if they could be
 * edited — the payment is the record, and this is its reflection.
 *
 * Nothing here decides whether the entry may be edited at all. A sealed entry
 * never reaches this form, and would be refused by the Doctrine listener even
 * if it did.
 *
 * @see \Augias\AccountingBundle\Tests\Functional\AccountingPagesTest
 * @extends AbstractType<LedgerEntry>
 */
final class LedgerEntryType extends AbstractType
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly LedgerTaxSplitter $taxSplitter,
        private readonly TaxRepository $taxes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mirrorsAPayment = $options['mirrors_a_payment'];

        $builder
            ->add('entryDate', DateType::class, [
                'label' => 'accounting.entry.form.entry_date',
                'help' => 'accounting.entry.form.entry_date_help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'disabled' => $mirrorsAPayment,
            ])
            ->add('label', TextType::class, [
                'label' => 'accounting.entry.form.label',
                'disabled' => $mirrorsAPayment,
            ])
            ->add('counterpartyName', TextType::class, [
                'label' => 'accounting.entry.form.counterparty',
                'disabled' => $mirrorsAPayment,
            ])
            ->add('documentReference', TextType::class, [
                'label' => 'accounting.entry.form.document_reference',
                'help' => 'accounting.entry.form.document_reference_help',
                'required' => false,
                'disabled' => $mirrorsAPayment,
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'accounting.entry.form.amount',
                'currency' => $options['currency'],
                'disabled' => $mirrorsAPayment,
            ])
            ->add('settlementMethod', EnumType::class, [
                'label' => 'accounting.entry.form.settlement_method',
                'class' => SettlementMethod::class,
                'choice_label' => static fn (SettlementMethod $method): string => $method->translationKey(),
                'required' => false,
                'placeholder' => '',
                'disabled' => $mirrorsAPayment,
            ]);

        // Only revenue is split by activity: the ceilings and the contribution
        // rates are per activity, and nothing about a purchase is.
        if ($options['book'] === LedgerBook::Revenue) {
            $builder->add('activityNature', EnumType::class, [
                'label' => 'accounting.entry.form.activity_nature',
                'help' => 'accounting.entry.form.activity_nature_help',
                'class' => ActivityNature::class,
                'choice_label' => static fn (ActivityNature $nature): string => $nature->translationKey(),
                'required' => false,
                'placeholder' => '',
            ]);
        }

        $builder->add('notes', TextareaType::class, [
            'label' => 'accounting.entry.form.notes',
            'required' => false,
        ]);

        // Added here rather than above because what it holds is read off the
        // entry: the rate is not a property, it is recovered from the split
        // already recorded — see storedTax().
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options, $mirrorsAPayment): void {
            $entry = $event->getData();
            $stored = $entry instanceof LedgerEntry ? $this->storedTax($entry) : null;

            $event->getForm()->add('tax', EntityType::class, [
                'label' => 'accounting.entry.form.tax',
                'help' => $options['vat_exempt']
                    ? 'accounting.entry.form.tax_help_exempt'
                    : 'accounting.entry.form.tax_help',
                'class' => Tax::class,
                // Flat-rate taxes are an absolute amount, not a percentage, so
                // there is nothing to take out of a receipt with one. They are
                // left out rather than offered and then refused.
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('t')
                    ->andWhere('t.type != :flat')
                    ->setParameter('flat', Tax::TYPE_FLAT_RATE)
                    ->orderBy('t.rate', 'ASC'),
                'choice_label' => static fn (Tax $tax): string => self::rateLabel($tax),
                'required' => false,
                'placeholder' => '',
                'mapped' => false,
                'data' => $stored['tax'] ?? null,
                // Locked in three cases, and each keeps what is already in the
                // books: a company outside the scope of VAT has none to record,
                // an entry mirroring a payment carries the payment's own split,
                // and a rate that has since been deleted can no longer be shown
                // as the choice it was — but the entry still holds it.
                'disabled' => $options['vat_exempt']
                    || $mirrorsAPayment
                    || ($stored['unresolved'] ?? false),
            ]);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($options): void {
            $entry = $event->getData();
            $form = $event->getForm();

            if (! $entry instanceof LedgerEntry || ! $form->has('tax') || $form->get('tax')->isDisabled()) {
                return;
            }

            $tax = $form->get('tax')->getData();

            if (! $tax instanceof Tax) {
                $entry->clearTax();

                return;
            }

            // Deducting tax requires holding the invoice that carries it
            // (CGI, art. 271-II), so a purchase claiming some has to say which
            // document it is claiming it from.
            if ($options['book'] === LedgerBook::Purchase && '' === trim((string) $entry->getDocumentReference())) {
                // Translated here rather than left as a key: a message added by
                // hand does not pass through the form theme's translator the
                // way a constraint violation does.
                $form->get('documentReference')->addError(new FormError(
                    $this->translator->trans('accounting.entry.document_reference_required_for_tax', [], 'validators'),
                ));

                return;
            }

            $split = $this->taxSplitter->forManualEntry($entry->getAmount(), $tax);

            $entry->setTax($split->net, $split->tax, $split->toArray());
        });
    }

    /**
     * The rate an entry was written with, recovered from its own split.
     *
     * The books record the figures, not the rate row that produced them — by
     * design, since a rate can be edited or deleted afterwards and the entry
     * must still say what was declared. So the choice is matched back by rate
     * and category, and `unresolved` says the entry holds a split no current
     * rate accounts for: the field is then shown locked rather than empty,
     * because an empty select that saves would quietly rewrite the books.
     *
     * @return array{tax: Tax|null, unresolved: bool}
     */
    private function storedTax(LedgerEntry $entry): array
    {
        $breakdown = $entry->getTaxBreakdown() ?? [];

        if ([] === $breakdown) {
            return ['tax' => null, 'unresolved' => false];
        }

        $share = $breakdown[0];

        foreach ($this->taxes->findAll() as $tax) {
            if (! $tax instanceof Tax || $tax->getCategory()->value !== $share['category']) {
                continue;
            }

            if (BigDecimal::of((string) ($tax->getRate() ?? 0))->toScale(4)->__toString() === $share['rate']) {
                return ['tax' => $tax, 'unresolved' => false];
            }
        }

        return ['tax' => null, 'unresolved' => true];
    }

    private static function rateLabel(Tax $tax): string
    {
        $label = sprintf('%s (%s%%)', $tax->getName() ?? '', $tax->getRate() ?? 0);

        return match ($tax->getCategory()) {
            TaxCategory::Standard => $label,
            default => sprintf('%s [%s]', $label, $tax->getCategory()->getLabel()),
        };
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LedgerEntry::class,
            'book' => LedgerBook::Revenue,
            'mirrors_a_payment' => false,
            // A company in franchise en base has no tax to separate out, so the
            // field is shown locked rather than hidden: the reason it cannot be
            // filled in is worth saying.
            'vat_exempt' => true,
            // The company's own currency: the money field scales by the
            // currency's decimal count, so the wrong one misplaces the decimal
            // point for JPY and BHD.
            'currency' => $this->systemConfig->getCurrency(),
        ]);

        $resolver->setAllowedTypes('book', LedgerBook::class);
        $resolver->setAllowedTypes('mirrors_a_payment', 'bool');
        $resolver->setAllowedTypes('vat_exempt', 'bool');
        $resolver->setAllowedTypes('currency', Currency::class);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'ledger_entry';
    }
}
