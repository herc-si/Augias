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
use Augias\McpBundle\Assistant\ActionPreview;
use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantAnswer;
use Augias\McpBundle\Assistant\AssistantConversation;
use Augias\McpBundle\Assistant\AssistantToolbox;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Augias\McpBundle\Assistant\CurrentCompany;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use function ignore_user_abort;
use function mb_substr;
use function str_starts_with;
use function trim;

/**
 * The assistant's side panel, in JSON: GET reads the conversation, POST
 * asks a question (`question`), settles the change the assistant proposed
 * (`confirm` or `cancel`, with its id) or starts over (`reset`).
 *
 * A change is never made because the model asked for it: it is shown, and
 * made only when the person confirms that very proposal.
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
    public const string CSRF = 'assistant';

    public const int MAX_QUESTION = 2000;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly AssistantConversation $conversation,
        private readonly AssistantToolbox $toolbox,
        private readonly ActionPreview $preview,
        private readonly CurrentCompany $currentCompany,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
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

        if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->state($company, $this->translator->trans('assistant.invalid_token'), Response::HTTP_FORBIDDEN);
        }

        if ($request->request->has('reset')) {
            $this->conversation->reset($company);

            return $this->state($company);
        }

        if ($this->conversation->isPending($company)) {
            return $this->state($company, $this->translator->trans('assistant.busy'), Response::HTTP_CONFLICT);
        }

        if ($request->request->has('confirm') || $request->request->has('cancel')) {
            return $this->settle($request, $company);
        }

        $question = mb_substr(trim((string) $request->request->get('question')), 0, self::MAX_QUESTION);

        if ($question === '') {
            return $this->state($company);
        }

        if ($this->conversation->action($company) !== null) {
            // Asking something else leaves the change unmade.
            $this->conversation->answer($company, $this->translator->trans('assistant.abandoned'));
        }

        $history = $this->conversation->messages($company);
        $this->conversation->ask($company, $question);
        $this->conversation->release();

        // Leaving the page must not lose the answer: it is written down all the same.
        ignore_user_abort(true);

        try {
            $this->record($company, $this->assistant->ask($question, (string) $company->getName(), $history));
        } catch (AssistantUnavailable) {
            $this->conversation->withdraw($company);

            return $this->state($company, $this->translator->trans('assistant.unavailable'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->state($company);
    }

    private function settle(Request $request, Company $company): Response
    {
        $confirmed = $request->request->has('confirm');
        $proposal = $this->conversation->settle($company, (string) $request->request->get($confirmed ? 'confirm' : 'cancel'));

        if ($proposal === null) {
            return $this->state($company, $this->translator->trans('assistant.action_gone'), Response::HTTP_CONFLICT);
        }

        if (! $confirmed) {
            $this->conversation->answer($company, $this->translator->trans('assistant.cancelled'));

            return $this->state($company);
        }

        $this->conversation->release();
        ignore_user_abort(true);

        $outcome = $this->toolbox->run($proposal->tool, $proposal->arguments);
        $failed = str_starts_with($outcome, 'Error:');
        $this->logger->info('Assistant made a confirmed change.', ['tool' => $proposal->tool, 'failed' => $failed]);

        try {
            $this->record($company, $this->assistant->resume($proposal, $outcome, (string) $company->getName()));
        } catch (AssistantUnavailable) {
            // Made or refused all the same: said plainly, without the model.
            $this->conversation->answer($company, $this->translator->trans($failed ? 'assistant.action_failed' : 'assistant.action_done'));
        }

        return $this->state($company);
    }

    private function record(Company $company, AssistantAnswer $answer): void
    {
        if ($answer->proposal !== null) {
            $this->conversation->propose($company, $answer->proposal, $this->preview->describe($answer->proposal->tool, $answer->proposal->arguments), $answer->text);

            return;
        }

        $this->conversation->answer($company, $answer->gaveUp || $answer->text === '' ? $this->translator->trans('assistant.gave_up') : $answer->text);
    }

    private function state(Company $company, ?string $error = null, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'messages' => $this->conversation->messages($company),
            'pending' => $this->conversation->isPending($company),
            'action' => $this->conversation->action($company),
            'error' => $error,
        ], $status);
    }
}
