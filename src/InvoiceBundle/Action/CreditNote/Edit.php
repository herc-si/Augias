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

use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Manager\CreditNoteFormManager;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use function assert;

/**
 * Opens the form for a draft credit note.
 *
 * An issued one is refused outright rather than rendered read-only: the whole
 * point of the document is that it stops changing once the client has it, and
 * this URL is reachable by hand whatever the buttons show.
 */
final readonly class Edit
{
    public function __construct(
        private CreditNoteFormManager $formManager,
        private RouterInterface $router,
    ) {
    }

    /**
     * @return array{dto: \Augias\InvoiceBundle\DTO\CreditNoteFormDTO, isEdit: true, creditNote: CreditNote}|Response
     */
    #[Template('@AugiasInvoice/CreditNote/create.html.twig')]
    public function __invoke(Request $request, CreditNote $creditNote): array | Response
    {
        if ($creditNote->isIssued()) {
            $session = $request->getSession();
            assert($session instanceof Session);
            $session->getFlashBag()->add('warning', 'credit_note.edit.issued');

            return new RedirectResponse(
                $this->router->generate('_credit_notes_view', ['id' => $creditNote->getId()]),
            );
        }

        return [
            'dto' => $this->formManager->createDTOFrom($creditNote),
            'isEdit' => true,
            'creditNote' => $creditNote,
        ];
    }
}
