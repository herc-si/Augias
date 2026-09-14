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

namespace Augias\InvoiceBundle\Form\Type;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Form\ClientAutocompleteType;
use Augias\CoreBundle\Form\Type\DiscountType;
use Augias\CoreBundle\Generator\BillingIdGenerator;
use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\CreditReason;
use Augias\MoneyBundle\Form\Type\HiddenMoneyType;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityRepository;
use JsonException;
use Money\Currency;
use Override;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;
use Symfonycasts\DynamicForms\DependentField;
use Symfonycasts\DynamicForms\DynamicFormBuilder;

/**
 * @see \Augias\InvoiceBundle\Tests\Form\Type\CreditNoteTypeTest
 * @extends AbstractType<CreditNoteFormDTO>
 */
class CreditNoteType extends AbstractType
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly BillingIdGenerator $billingIdGenerator,
    ) {
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|JsonException
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder = new DynamicFormBuilder($builder);

        $builder->add('client', ClientAutocompleteType::class, [
            'placeholder' => 'credit_note.client_choose',
        ]);

        $builder->add('reason', EnumType::class, [
            'class' => CreditReason::class,
            'label' => 'credit_note.field.reason',
            'placeholder' => 'credit_note.reason.choose',
            'choice_label' => static fn (CreditReason $reason): string => $reason->getLabel(),
        ]);

        // Only the chosen client's invoices, and the field stays optional: a
        // rebate or a gesture credits no single invoice.
        $builder->addDependent('creditedInvoice', 'client', function (DependentField $field, ?Client $client): void {
            if (! $client instanceof Client || ! $client->getId() instanceof Ulid) {
                return;
            }

            $clientId = $client->getId();
            $field->add(EntityType::class, [
                'class' => Invoice::class,
                'label' => 'credit_note.field.credited_invoice',
                'placeholder' => 'credit_note.invoice.none',
                'required' => false,
                'choice_label' => 'invoiceId',
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('i')
                    ->where('i.client = :client')
                    ->setParameter('client', $clientId, UlidType::NAME)
                    ->orderBy('i.invoiceDate', 'DESC'),
            ]);
        });

        $builder->addDependent('users', 'client', function (DependentField $field, ?Client $client): void {
            if (! $client instanceof Client || ! $client->getId() instanceof Ulid) {
                return;
            }

            $clientId = $client->getId();
            $field->add(EntityType::class, [
                'class' => Contact::class,
                'constraints' => new NotBlank(),
                'expanded' => true,
                'multiple' => true,
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('c')
                    ->where('c.client = :client')
                    ->setParameter('client', $clientId, UlidType::NAME),
            ]);
        });

        $builder->add('discount', DiscountType::class, [
            'required' => false,
            'label' => 'billing.discount',
            'currency' => $options['currency'],
        ]);

        // ItemType is shared with invoices; only the row's class differs, so a
        // line added here is a CreditNoteLine rather than a plain Line.
        $builder->add('lines', LiveCollectionType::class, [
            'entry_type' => ItemType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'required' => false,
            'entry_options' => [
                'currency' => $options['currency'],
                'data_class' => CreditNoteLine::class,
            ],
        ]);

        $dto = $options['data'] ?? new CreditNoteFormDTO();

        $number = '' !== $dto->creditNoteId
            ? $dto->creditNoteId
            : $this->billingIdGenerator->generate(new CreditNote(), ['field' => 'creditNoteId']);

        $builder->add('creditNoteId', null, [
            'label' => 'credit_note.field.number',
            'data' => $number,
            'empty_data' => '',
            'attr' => ['maxlength' => 255],
        ]);

        $builder->add('creditNoteDate', DateType::class, [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'label' => 'credit_note.field.date',
        ]);

        $builder->add('terms', null, ['label' => 'form.field.terms']);
        $builder->add('notes', null, ['help' => 'billing.notes_help']);
        $builder->add('total', HiddenMoneyType::class, ['currency' => $options['currency']]);
        $builder->add('baseTotal', HiddenMoneyType::class, ['currency' => $options['currency']]);
        $builder->add('tax', HiddenMoneyType::class, ['currency' => $options['currency']]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'credit_note';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => CreditNoteFormDTO::class,
                'currency' => $this->systemConfig->getCurrency(),
            ])
            ->setAllowedTypes('currency', [Currency::class]);
    }
}
