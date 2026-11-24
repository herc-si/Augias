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

namespace Augias\McpBundle\Action;

use Augias\CoreBundle\Entity\Company;
use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantConversation;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Augias\McpBundle\Assistant\CurrentCompany;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use function ignore_user_abort;
use function mb_substr;
use function trim;

/**
 * The side panel's side of the conversation, in JSON: GET reads it, POST
 * asks a question (`question`) or starts over (`reset`).
 *
 * The question is written down before the model is asked and the session
 * let go meanwhile: a page opened while the model thinks shows the question
 * waiting, then its answer, and is not held up.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantPanelTest
 */
#[Route(path: '/assistant/messages', name: '_assistant_messages', methods: ['GET', 'POST'])]
final class AssistantMessagesAction extends AbstractController
{
    public function __construct(
        private readonly Assistant $assistant,
        private readonly AssistantConversation $conversation,
        private readonly CurrentCompany $currentCompany,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany->get();

        if (! $this->assistant->isAvailable() || ! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        if (! $request->isMethod('POST')) {
            return $this->state($company);
        }

        if (! $this->isCsrfTokenValid(AssistantAction::CSRF, (string) $request->request->get('_token'))) {
            return $this->state($company, $this->translator->trans('assistant.invalid_token'), Response::HTTP_FORBIDDEN);
        }

        if ($request->request->has('reset')) {
            $this->conversation->reset($company);

            return $this->state($company);
        }

        $question = mb_substr(trim((string) $request->request->get('question')), 0, AssistantAction::MAX_QUESTION);

        if ($question === '') {
            return $this->state($company);
        }

        if ($this->conversation->isPending($company)) {
            return $this->state($company, $this->translator->trans('assistant.busy'), Response::HTTP_CONFLICT);
        }

        $history = $this->conversation->messages($company);
        $this->conversation->ask($company, $question);
        $this->conversation->release();

        // Leaving the page must not lose the answer: it is written down all the same.
        ignore_user_abort(true);

        try {
            $answer = $this->assistant->ask($question, (string) $company->getName(), $history);
        } catch (AssistantUnavailable) {
            $this->conversation->withdraw($company);

            return $this->state($company, $this->translator->trans('assistant.unavailable'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->conversation->answer($company, $answer->gaveUp || $answer->text === '' ? $this->translator->trans('assistant.gave_up') : $answer->text);

        return $this->state($company);
    }

    private function state(Company $company, ?string $error = null, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'messages' => $this->conversation->messages($company),
            'pending' => $this->conversation->isPending($company),
            'error' => $error,
        ], $status);
    }
}
