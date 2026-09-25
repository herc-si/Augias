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

namespace Augias\TaxBundle\Twig\Components;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\SettingsBundle\SystemConfig;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\CompanyTaxIdentifiersFormType;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Augias\TaxBundle\Repository\TaxIdentifierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use function trim;

#[AsLiveComponent]
final class CompanyTaxIdentifiers extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    public function __construct(
        private readonly TaxIdentifierRepository $repository,
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $em,
        private readonly SystemConfig $systemConfig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $data = ['siret' => null, 'siren' => null, 'vatNumber' => null, 'identifiers' => []];

        foreach ($this->savedIdentifiers() as $identifier) {
            $field = self::FIELDS[(string) $identifier->getLabel()] ?? null;

            if (null === $field) {
                $data['identifiers'][] = $identifier;
            } elseif (null === $data[$field]) {
                $data[$field] = $identifier->getValue();
            }
        }

        return $this->createForm(CompanyTaxIdentifiersFormType::class, $data);
    }

    /**
     * The identifiers that have a field of their own, by label.
     */
    private const array FIELDS = [
        TaxIdentifierType::SIRET => 'siret',
        TaxIdentifierType::SIREN => 'siren',
        TaxIdentifierType::VAT_NUMBER => 'vatNumber',
    ];

    /**
     * @return list<TaxIdentifier>
     */
    private function savedIdentifiers(): array
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId instanceof Ulid ? $this->repository->findCompanyIdentifiers($companyId) : [];
    }

    #[LiveAction]
    public function save(): ?RedirectResponse
    {
        $this->submitForm();
        /** @var array{siret: string|null, siren: string|null, vatNumber: string|null, identifiers: list<TaxIdentifier>} $data */
        $data = $this->getForm()->getData();
        $existing = $this->savedIdentifiers();

        // The list holds the others; the three fields become identifiers again
        // here, reusing the saved one for each so its id — and anything that
        // points at it — survives the save.
        $submitted = $data['identifiers'] ?? [];

        foreach (self::FIELDS as $label => $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ('' === $value) {
                continue;
            }

            $identifier = null;

            foreach ($existing as $candidate) {
                if ($candidate->getLabel() === $label) {
                    $identifier = $candidate;

                    break;
                }
            }

            $submitted[] = ($identifier ?? new TaxIdentifier()->setLabel($label))->setValue($value);
        }

        if ($this->systemConfig->get(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH) === '1') {
            $required = ['siret' => 'tax.company_identifiers.siret_required'];

            // A company in franchise usually has no VAT number, and its
            // e-invoices name it by SIRET instead (BT-32).
            if (! $this->systemConfig->isVatExempt()) {
                $required['vatNumber'] = 'tax.company_identifiers.vat_required';
            }

            foreach ($required as $field => $errorMessage) {
                if ('' === trim((string) ($data[$field] ?? ''))) {
                    $this->getForm()->get($field)->addError(new FormError($this->translator->trans($errorMessage)));
                }
            }

            if (! $this->getForm()->isValid()) {
                return null;
            }
        }

        $submittedIds = [];
        foreach ($submitted as $identifier) {
            $identifier->setClient(null);
            $this->em->persist($identifier);

            if ($identifier->getId() !== null) {
                $submittedIds[(string) $identifier->getId()] = true;
            }
        }

        foreach ($existing as $identifier) {
            if (! isset($submittedIds[(string) $identifier->getId()])) {
                $this->em->remove($identifier);
            }
        }

        $this->em->flush();
        $this->addFlash('success', 'settings.saved.success');
        return $this->redirectToRoute('_settings', ['section' => 'system']);
    }
}
