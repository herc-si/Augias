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

namespace Augias\InvoiceBundle\Action\CreditNote;

use Augias\InvoiceBundle\DTO\CreditNoteAllocationDTO;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Enum\AllocationKind;
use Augias\InvoiceBundle\Exception\AllocationException;
use Augias\InvoiceBundle\Form\Type\CreditNoteAllocationType;
use Augias\InvoiceBundle\Service\CreditNoteAllocator;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use function assert;

/**
 * Records what became of a credit note: set against an invoice, or paid back.
 *
 * The rules live in {@see CreditNoteAllocator}, not here — a caller that never
 * goes near this form is held to the same ones.
 */
final class Allocate extends AbstractController
{
    public function __construct(
        private readonly CreditNoteAllocator $allocator,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * @throws MathException
     */
    public function __invoke(Request $request, CreditNote $creditNote): Response
    {
        $route = $this->router->generate('_credit_notes_view', ['id' => $creditNote->getId()]);

        $dto = new CreditNoteAllocationDTO();
        $dto->allocatedOn = CarbonImmutable::now();

        $clientId = $creditNote->getClient()->getId();
        assert(null !== $clientId);

        $form = $this->createForm(CreditNoteAllocationType::class, $dto, [
            'currency' => $creditNote->getClient()->getCurrency(),
            'client_id' => $clientId,
        ]);
        $form->handleRequest($request);

        $session = $request->getSession();
        assert($session instanceof Session);

        if (! $form->isSubmitted() || ! $form->isValid()) {
            $session->getFlashBag()->add('danger', 'credit_note.allocation.invalid');

            return new RedirectResponse($route);
        }

        // Guaranteed by the form's own NotNull constraints, which have already
        // passed by the time we get here.
        assert($dto->kind instanceof AllocationKind);
        assert(null !== $dto->amount);

        try {
            $this->allocator->allocate(
                $creditNote,
                $dto->kind,
                $dto->amount,
                $dto->invoice,
                $dto->allocatedOn,
                $dto->notes,
            );
        } catch (AllocationException $exception) {
            // The allocator's message says which rule was broken and by how
            // much, which is more use than a generic refusal.
            $session->getFlashBag()->add('danger', $exception->getMessage());

            return new RedirectResponse($route);
        }

        $session->getFlashBag()->add('success', 'credit_note.allocation.recorded');

        return new RedirectResponse($route);
    }
}
