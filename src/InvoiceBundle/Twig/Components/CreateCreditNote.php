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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Repository\ClientRepository;
use Augias\CoreBundle\Billing\TotalCalculator;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Entity\Discount;
use Augias\InvoiceBundle\DTO\CreditNoteFormDTO;
use Augias\InvoiceBundle\Email\CreditNoteEmail;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Form\Type\CreditNoteType;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\MoneyBundle\Calculator;
use Augias\TaxBundle\Service\TaxAvailability;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use function assert;

/**
 * Much thinner than {@see CreateInvoice}: no inline client creation, no
 * catalogue picker, no custom fields. A credit note answers to something that
 * already happened, so the client is always known and usually the invoice too.
 *
 * @see \Augias\InvoiceBundle\Tests\Twig\Components\CreateCreditNoteTest
 */
#[AsLiveComponent]
final class CreateCreditNote extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    /**
     * Not a LiveProp: the live state lives in $formValues, and the DTO is
     * rebuilt from it on every render.
     */
    public CreditNoteFormDTO $dto;

    #[LiveProp(writable: false)]
    public bool $isEdit = false;

    #[LiveProp(writable: false, fieldName: 'creditNoteEntity')]
    public ?CreditNote $creditNote = null;

    public function __construct(
        private readonly TaxAvailability $taxAvailability,
        private readonly ClientRepository $clientRepository,
        private readonly TotalCalculator $totalCalculator,
        private readonly CreditNoteFormManager $formManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowInterface $creditNoteStateMachine,
        private readonly MailerInterface $mailer,
        private readonly RouterInterface $router,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly Calculator $calculator,
    ) {
    }

    /**
     * Runs after submitFormOnRender(), so the DTO already carries what was
     * typed.
     *
     * @throws MathException
     */
    #[PreReRender(priority: -10)]
    public function calculateTotals(): void
    {
        try {
            $draft = $this->formManager->createFromDTO($this->dto);
        } catch (InvalidArgumentException) {
            // No client picked yet — there is nothing to total.
            $this->dto->total = '0';
            $this->dto->baseTotal = '0';
            $this->dto->tax = '0';

            return;
        }

        $this->totalCalculator->calculateTotals($draft);

        $this->dto->total = (string) $draft->getTotal();
        $this->dto->baseTotal = (string) $draft->getBaseTotal();
        $this->dto->tax = (string) $draft->getTax();
    }

    /**
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $options = [];

        if ($this->dto->client instanceof Client) {
            $options['currency'] = $this->dto->client->getCurrency();
        } elseif (($this->formValues['client'] ?? '') !== '') {
            $client = $this->clientRepository->find($this->formValues['client']);
            $options['currency'] = $client?->getCurrency();
        }

        return $this->createForm(CreditNoteType::class, $this->dto, $options);
    }

    #[LiveAction]
    public function clearClient(): void
    {
        $this->formValues['client'] = null;
        $this->formValues['users'] = [];
        $this->formValues['creditedInvoice'] = null;
    }

    #[LiveAction]
    public function saveDraft(): ?Response
    {
        return $this->persist(false, false);
    }

    /**
     * Issuing is the gesture that fixes the document. Nothing undoes it.
     */
    #[LiveAction]
    public function saveIssue(): ?Response
    {
        return $this->persist(true, false);
    }

    #[LiveAction]
    public function saveSend(): ?Response
    {
        if ($this->emailVerificationGate->isGated()) {
            $this->addFlash('error', 'email_verification.flash.send_invoice');

            return null;
        }

        return $this->persist(true, true);
    }

    #[ExposeInTemplate]
    public function hasTax(): bool
    {
        return $this->taxAvailability->isOffered();
    }

    #[ExposeInTemplate]
    public function hasTermsOrNotes(): bool
    {
        return (null !== $this->dto->terms && '' !== $this->dto->terms)
            || (null !== $this->dto->notes && '' !== $this->dto->notes);
    }

    /**
     * Recalculated from the lines rather than read off the DTO: a percentage
     * discount is worked out against baseTotal + tax, and those two arrive as
     * hidden fields the browser maintains. While a line is being edited they
     * lag, and a discount read against a stale zero shows as none at all.
     */
    #[ExposeInTemplate]
    public function getDiscountAmount(): string
    {
        if (! $this->dto->discount instanceof Discount) {
            return '0';
        }

        try {
            $draft = $this->formManager->createFromDTO($this->dto);
        } catch (InvalidArgumentException) {
            return '0';
        }

        $this->totalCalculator->calculateTotals($draft);

        return (string) $this->calculator->calculateDiscount($draft);
    }

    private function persist(bool $issue, bool $send): ?Response
    {
        $this->submitForm();

        $form = $this->getForm();

        if (! $form->isValid()) {
            return null;
        }

        $dto = $form->getData();
        assert($dto instanceof CreditNoteFormDTO);

        if ($this->isEdit) {
            assert($this->creditNote instanceof CreditNote);
            $creditNote = $this->creditNote;
            $this->formManager->updateFromDTO($creditNote, $dto);
        } else {
            $creditNote = $this->formManager->createFromDTO($dto);
            $this->entityManager->persist($creditNote);
        }

        if ($issue && $this->creditNoteStateMachine->can($creditNote, CreditNoteGraph::TRANSITION_ISSUE)) {
            $this->creditNoteStateMachine->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
            $creditNote->setIssuedAt(CarbonImmutable::now());
        }

        $this->totalCalculator->calculateTotals($creditNote);
        $this->entityManager->flush();

        if ($send) {
            if ($creditNote->getUsers()->isEmpty()) {
                $this->addFlash('error', 'credit_note.send.no_recipients');
            } else {
                $this->mailer->send(new CreditNoteEmail($creditNote));
            }
        }

        $this->addFlash('success', $this->isEdit ? 'credit_note.edit.success' : 'credit_note.create.success');

        return $this->redirect(
            $this->router->generate('_credit_notes_view', ['id' => $creditNote->getId()]),
        );
    }
}
