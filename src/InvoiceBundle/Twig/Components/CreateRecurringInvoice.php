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

namespace Augias\InvoiceBundle\Twig\Components;

use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Twig\Components\ManagesNoteLines;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\InvoiceBundle\Form\Type\RecurringInvoiceType;
use Augias\TaxBundle\Service\TaxAvailability;
use Brick\Math\Exception\MathException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsLiveComponent]
final class CreateRecurringInvoice extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;
    use ManagesNoteLines;

    #[LiveProp(writable: true, fieldName: 'formData')]
    public RecurringInvoice $invoice;

    #[LiveProp(writable: true)]
    public bool $isEdit = false;

    public function __construct(
        private readonly TaxAvailability $taxAvailability,
        private readonly ClientRepository $clientRepository,
        private readonly TotalCalculator $totalCalculator
    ) {
    }

    /**
     * @throws MathException
     */
    #[PreReRender]
    public function preRender(): void
    {
        $this->totalCalculator->calculateTotals($this->invoice);
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $options = [];

        if (($this->formValues['client'] ?? '') !== '') {
            $client = $this->clientRepository->find($this->formValues['client']);
            $options['currency'] = $client?->getCurrency();
        }

        return $this->createForm(RecurringInvoiceType::class, $this->invoice, $options);
    }

    public function canAddLines(): bool
    {
        return ($this->formValues['client'] ?? '') !== '' || $this->invoice->getClient() !== null;
    }

    #[LiveAction]
    public function clearClient(): void
    {
        $this->formValues['client'] = null;
    }

    #[ExposeInTemplate]
    public function hasTax(): bool
    {
        return $this->taxAvailability->isOffered();
    }
}
