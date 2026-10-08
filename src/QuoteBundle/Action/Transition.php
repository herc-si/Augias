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

namespace Augias\QuoteBundle\Action;

use Augias\CoreBundle\Response\FlashResponse;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Enum\QuoteStatus;
use Augias\QuoteBundle\Exception\InvalidTransitionException;
use Generator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class Transition
{
    public function __construct(
        private WorkflowInterface $quoteStateMachine,
        private RouterInterface $router
    ) {
    }

    /**
     * @throws InvalidTransitionException
     */
    public function __invoke(Request $request, string $action, Quote $quote): RedirectResponse
    {
        if (! $this->quoteStateMachine->can($quote, $action)) {
            throw new InvalidTransitionException($action);
        }

        $marking = $this->quoteStateMachine->apply($quote, $action);

        $route = $this->router->generate('_quotes_view', ['id' => $quote->getId()]);

        // An accepted quote stays on its page, where "Create the invoice" now
        // is: the invoice is no longer made, and numbered, on acceptance.
        if ($marking->has(QuoteStatus::Archived->value)) {
            $route = $this->router->generate('_quotes_index');
        }

        return new class($action, $route) extends RedirectResponse implements FlashResponse {
            public function __construct(
                private readonly string $action,
                string $route
            ) {
                parent::__construct($route);
            }

            public function getFlash(): Generator
            {
                yield self::FLASH_SUCCESS => 'quote.transition.action.' . $this->action;
            }
        };
    }
}
