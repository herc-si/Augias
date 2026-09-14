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

use Augias\InvoiceBundle\DTO\CreditNoteAllocationDTO;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Doctrine\ORM\EntityRepository;
use Money\Currency;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Ulid;

/**
 * @extends AbstractType<CreditNoteAllocationDTO>
 */
final class CreditNoteAllocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', EnumType::class, [
            'class' => AllocationKind::class,
            'label' => 'credit_note.allocation.kind',
            'choice_label' => static fn (AllocationKind $kind): string => $kind->getLabel(),
        ]);

        // No divisor here: MoneyExtension swaps in its own view transformer,
        // which scales between major and minor units using the currency's real
        // subunit. Setting divisor as well would scale twice.
        $builder->add('amount', MoneyType::class, [
            'label' => 'credit_note.allocation.amount',
            'currency' => $options['currency'],
        ]);

        // Only invoices of this client, and only ones that still owe something:
        // setting a credit against a paid or cancelled invoice changes nothing
        // and hides the credit.
        $clientId = $options['client_id'];

        $builder->add('invoice', EntityType::class, [
            'class' => Invoice::class,
            'label' => 'credit_note.allocation.invoice',
            'placeholder' => 'credit_note.allocation.no_invoice',
            'required' => false,
            'choice_label' => 'invoiceId',
            'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('i')
                ->where('i.client = :client')
                ->andWhere('i.status IN (:open)')
                ->setParameter('client', $clientId, UlidType::NAME)
                ->setParameter('open', [InvoiceStatus::Pending, InvoiceStatus::Overdue])
                ->orderBy('i.invoiceDate', 'DESC'),
        ]);

        $builder->add('allocatedOn', DateType::class, [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'label' => 'credit_note.allocation.date',
        ]);

        $builder->add('notes', TextareaType::class, [
            'label' => 'credit_note.allocation.notes',
            'required' => false,
            'attr' => ['rows' => 2],
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'credit_note_allocation';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => CreditNoteAllocationDTO::class])
            ->setRequired(['currency', 'client_id'])
            ->setAllowedTypes('currency', [Currency::class])
            ->setAllowedTypes('client_id', [Ulid::class]);
    }
}
