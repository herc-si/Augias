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

use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantConversation;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Augias\McpBundle\Assistant\CurrentCompany;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use function mb_substr;
use function trim;

/**
 * The built-in assistant's page: a question, its answer, and the
 * conversation so far, the same one as in the side panel
 * (AssistantMessagesAction).
 *
 * Not offered until the model is configured (AssistantClient).
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantPageTest
 */
#[Route(path: '/assistant', name: '_assistant', methods: ['GET', 'POST'])]
final class AssistantAction extends AbstractController
{
    public const string CSRF = 'assistant';

    public const int MAX_QUESTION = 2000;

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

        if (! $this->assistant->isAvailable() || $company === null) {
            throw new NotFoundHttpException();
        }

        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'assistant.invalid_token');

                return $this->redirectToRoute('_assistant');
            }

            if ($request->request->has('reset')) {
                $this->conversation->reset($company);

                return $this->redirectToRoute('_assistant');
            }

            $question = mb_substr(trim((string) $request->request->get('question')), 0, self::MAX_QUESTION);

            if ($question !== '' && ! $this->conversation->isPending($company)) {
                $history = $this->conversation->messages($company);
                $this->conversation->ask($company, $question);

                try {
                    $answer = $this->assistant->ask($question, (string) $company->getName(), $history);
                    $this->conversation->answer($company, $answer->gaveUp || $answer->text === '' ? $this->translator->trans('assistant.gave_up') : $answer->text);
                } catch (AssistantUnavailable) {
                    $this->conversation->withdraw($company);
                    $this->addFlash('error', 'assistant.unavailable');
                }
            }

            return $this->redirectToRoute('_assistant');
        }

        return $this->render('@AugiasMcp/Assistant/index.html.twig', [
            'history' => $this->conversation->messages($company),
            'company' => $company,
        ]);
    }
}
