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

namespace Augias\ClientBundle\Form\Type;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Enum\CustomFieldTarget;
use Augias\CoreBundle\Form\Type\CustomFieldValueCollectionType;
use Augias\MoneyBundle\Form\Type\CurrencyType;
use Augias\SaasBundle\Feature\Feature;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Override;
use RuntimeException;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @see \Augias\ClientBundle\Tests\Form\Type\ClientTypeTest
 * @extends AbstractType<Client>
 */
class ClientType extends AbstractType
{
    public function __construct(
        private readonly FeatureGate $featureGate,
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', null, ['label' => 'client.form.name.label', 'help' => 'client.form.name.help', 'required' => false, 'sanitize_html' => true, 'allow_single_quotes' => true]);
        $builder->add('isClient', CheckboxType::class, ['label' => 'client.form.is_client.label', 'required' => false]);
        $builder->add('isSupplier', CheckboxType::class, ['label' => 'client.form.is_supplier.label', 'required' => false]);
        $builder->add('website', UrlType::class, ['label' => 'client.form.website.label', 'required' => false, 'default_protocol' => 'http']);

        if ($this->featureGate->isEnabled(Feature::MultiCurrency->value)) {
            $builder->add(
                'currencyCode',
                CurrencyType::class,
                [
                    'label' => 'client.form.currency_code.label',
                    'placeholder' => 'client.form.currency.empty_value',
                    'required' => false,
                ]
            );
        } else {
            $defaultCurrency = $this->resolveDefaultCurrencyCode();

            $builder->add(
                'currencyCode',
                CurrencyType::class,
                [
                    'label' => 'client.form.currency_code.label',
                    'placeholder' => 'client.form.currency.empty_value',
                    'required' => false,
                    'disabled' => true,
                    'data' => $defaultCurrency,
                    'feature_gated' => Feature::MultiCurrency->value,
                ]
            );

            $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use ($defaultCurrency): void {
                $client = $event->getData();

                // Only override when a concrete default currency is available — passing null
                // would clear the existing currency on the entity when editing.
                if ($client instanceof Client && null !== $defaultCurrency) {
                    $client->setCurrencyCode($defaultCurrency);
                }
            });
        }

        $builder->add(
            'contacts',
            LiveCollectionType::class,
            [
                'entry_type' => ContactType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'button_delete_options' => [
                    'label_html' => true,
                ],
            ]
        );

        $builder->add(
            'addresses',
            LiveCollectionType::class,
            [
                'entry_type' => AddressType::class,
                'entry_options' => [
                    'data_class' => Address::class,
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'required' => false,
            ]
        );

        // The three identifiers every French business has, in fields of their
        // own: in the list below, they had to be picked from a dropdown to be
        // given at all.
        $builder->add('siret', TextType::class, [
            'label' => 'client.form.siret',
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'maxlength' => 14],
        ]);
        $builder->add('siren', TextType::class, [
            'label' => 'client.form.siren',
            'required' => false,
            'attr' => ['inputmode' => 'numeric', 'maxlength' => 9],
        ]);
        $builder->add('vatNumber', TextType::class, [
            'label' => 'client.form.vat_number',
            'required' => false,
        ]);

        $builder->add(
            'otherTaxIdentifiers',
            LiveCollectionType::class,
            [
                'entry_type' => TaxIdentifierType::class,
                'entry_options' => ['labels' => TaxIdentifierType::OTHER_LABELS],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'required' => false,
            ]
        );

        if ($this->featureGate->isEnabled(Feature::CustomFields->value)) {
            $builder->add('customFields', CustomFieldValueCollectionType::class, [
                'target' => CustomFieldTarget::CLIENT,
                'parent_record' => $options['data'] ?? null,
                'manage_persistence' => false,
            ]);
        }

        // Not every client is a company — an individual can be added without
        // typing a name twice: leave "Name" empty and it's filled in from the
        // first contact's own name instead. Whichever branch fires also sets
        // isCompany, since that's the only signal we have for it — driving
        // RequiredFiscalIdentifierForElectronicInvoicingValidator, which
        // shouldn't demand a SIRET from a private individual.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $client = $event->getData();

            if (! $client instanceof Client) {
                return;
            }

            if (trim((string) $client->getName()) !== '') {
                $client->setIsCompany(true);

                return;
            }

            $primaryContact = $client->getContacts()->first();

            if ($primaryContact instanceof Contact) {
                $client->setName(trim($primaryContact->getFirstName() . ' ' . $primaryContact->getLastName()));
                $client->setIsCompany(false);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Client::class,
            'validation_groups' => ['Default', 'form'],
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'client';
    }

    private function resolveDefaultCurrencyCode(): ?string
    {
        try {
            return $this->systemConfig->getCurrency()
                ->getCode();
        } catch (RuntimeException) {
            return null;
        }
    }
}
