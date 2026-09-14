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

use Augias\CoreBundle\Response\FlashResponse;
use Augias\CoreBundle\Traits\SaveableTrait;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Exception\InvalidTransitionException;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Carbon\CarbonImmutable;
use Generator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final class Transition
{
    use SaveableTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly WorkflowInterface $creditNoteStateMachine,
    ) {
    }

    public function __invoke(Request $request, string $action, CreditNote $creditNote): RedirectResponse
    {
        if (! $this->creditNoteStateMachine->can($creditNote, $action)) {
            throw new InvalidTransitionException($action);
        }

        $this->creditNoteStateMachine->apply($creditNote, $action);

        // The moment the document stops being editable is worth recording, and
        // it is not the same as the date printed on it.
        if (CreditNoteGraph::TRANSITION_ISSUE === $action) {
            $creditNote->setIssuedAt(CarbonImmutable::now());
        }

        $this->save($creditNote);

        $route = $this->router->generate('_credit_notes_view', ['id' => $creditNote->getId()]);

        return new class($action, $route) extends RedirectResponse implements FlashResponse {
            public function __construct(
                private readonly string $action,
                string $route,
            ) {
                parent::__construct($route);
            }

            public function getFlash(): Generator
            {
                yield FlashResponse::FLASH_SUCCESS => 'credit_note.transition.' . $this->action;
            }
        };
    }
}
