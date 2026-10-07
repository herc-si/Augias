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

namespace Augias\ClientBundle\Twig\Components;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Form\Type\ClientType;
use Augias\ClientBundle\Registry\CompanyRegistry;
use Augias\ClientBundle\Registry\RegistryCompany;
use Augias\CoreBundle\Enum\CustomFieldTarget;
use Augias\CoreBundle\Service\CustomField\CustomFieldFormWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * @see \Augias\ClientBundle\Tests\Twig\Components\ClientFormTest
 */
#[AsLiveComponent]
class ClientForm extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Client $client = null;

    /**
     * What is typed in the register search: a name, a SIREN or a SIRET.
     */
    #[LiveProp(writable: true)]
    public string $registryQuery = '';

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly CustomFieldFormWriter $customFieldFormWriter,
        private readonly CompanyRegistry $registry,
    ) {
    }

    /**
     * Whether an existing record is being edited. Not "has an id": a new
     * record is handed its ULID on construction, and the supplier add page
     * passes one in already marked as a supplier.
     */
    public function isEditing(): bool
    {
        return $this->client instanceof Client && $this->manager->contains($this->client);
    }

    /**
     * Whether the record being added is a supplier only, to title the page.
     */
    public function isAddingASupplier(): bool
    {
        return ! $this->isEditing() && $this->client instanceof Client && $this->client->isSupplier() && ! $this->client->isClient();
    }

    /**
     * The companies of the French register matching the search.
     *
     * @return list<RegistryCompany>
     */
    public function getRegistryResults(): array
    {
        return $this->registry->search($this->registryQuery);
    }

    /**
     * Fills in the client from the register: its name, SIREN, SIRET (the
     * head office's), VAT number and head office address. What was typed in
     * the other fields stays.
     */
    #[LiveAction]
    public function fillFromRegistry(#[LiveArg] string $siren): void
    {
        $company = $this->registry->find($siren);
        $this->registryQuery = '';

        if (! $company instanceof RegistryCompany) {
            return;
        }

        $this->formValues['name'] = $company->name;
        $this->formValues['siren'] = $company->siren;
        $this->formValues['siret'] = $company->siret ?? '';
        $this->formValues['vatNumber'] = $company->vatNumber();

        $addresses = is_array($this->formValues['addresses'] ?? null) ? $this->formValues['addresses'] : [];
        $key = array_key_first($addresses) ?? 0;
        $address = is_array($addresses[$key] ?? null) ? $addresses[$key] : [];
        $addresses[$key] = array_merge($address, [
            'street1' => $company->street1 ?? '',
            'street2' => $company->street2 ?? '',
            'zip' => $company->zip ?? '',
            'city' => $company->city ?? '',
            'country' => 'FR',
        ]);
        $this->formValues['addresses'] = $addresses;
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            ClientType::class,
            $this->client ?? new Client()
                ->addContact(new Contact())
                ->addAddress(new Address()),
            ['validation_groups' => ['Default', 'form']]
        );
    }

    #[LiveAction]
    public function save(): RedirectResponse | null
    {
        $this->submitForm();

        if (! $this->getForm()->isValid()) {
            return null;
        }

        /** @var Client $client */
        $client = $this->getForm()->getData();
        foreach ($client->getAddresses() as $address) {
            if ($address->isEmpty()) {
                $client->removeAddress($address);
            }
        }

        $this->manager->persist($client);
        $this->manager->flush();

        $form = $this->getForm();

        if ($form->has('customFields')) {
            $this->customFieldFormWriter->write(
                $form->get('customFields'),
                CustomFieldTarget::CLIENT,
                $client->getId(),
                $client,
            );
        }

        foreach ($form->get('contacts') as $contactForm) {
            if ($contactForm->has('customFields')) {
                /** @var Contact $contact */
                $contact = $contactForm->getData();
                $this->customFieldFormWriter->write(
                    $contactForm->get('customFields'),
                    CustomFieldTarget::CONTACT,
                    $contact->getId(),
                    $contact,
                );
            }
        }

        $this->manager->flush();

        $this->addFlash('success', 'client.create.success');
        return $this->redirectToRoute('_clients_view', [
            'id' => $client->getId(),
        ]);
    }
}
