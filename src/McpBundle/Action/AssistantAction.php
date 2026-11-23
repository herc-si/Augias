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

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\McpBundle\Assistant\Assistant;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_filter;
use function array_slice;
use function array_values;
use function is_array;
use function is_string;
use function mb_substr;
use function trim;

/**
 * The built-in assistant's page: a question, its answer, and the
 * conversation so far, kept in the session for the company it is about.
 *
 * Not offered until the model is configured (AssistantClient).
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantPageTest
 */
#[Route(path: '/assistant', name: '_assistant', methods: ['GET', 'POST'])]
final class AssistantAction extends AbstractController
{
    public const string CSRF = 'assistant';

    /** Questions and answers kept for the model to follow the thread. */
    private const int HISTORY = 12;

    private const int MAX_QUESTION = 2000;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (! $this->assistant->isAvailable()) {
            throw new NotFoundHttpException();
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->entityManager->find(Company::class, $companyId) : null;

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        $session = $request->getSession();
        $key = 'assistant_history_' . $company->getId()->toBase32();
        $history = $session->get($key, []);
        $history = is_array($history) ? $history : [];

        if ($request->isMethod('POST')) {
            if (! $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'assistant.invalid_token');

                return $this->redirectToRoute('_assistant');
            }

            if ($request->request->has('reset')) {
                $session->remove($key);

                return $this->redirectToRoute('_assistant');
            }

            $question = mb_substr(trim((string) $request->request->get('question')), 0, self::MAX_QUESTION);

            if ($question !== '') {
                try {
                    $answer = $this->assistant->ask($question, (string) $company->getName(), $history);
                    $history[] = ['role' => 'user', 'content' => $question];
                    $history[] = ['role' => 'assistant', 'content' => $answer->gaveUp || $answer->text === '' ? $this->translator->trans('assistant.gave_up') : $answer->text];
                    $session->set($key, array_slice($history, -self::HISTORY));
                } catch (AssistantUnavailable) {
                    $this->addFlash('error', 'assistant.unavailable');
                }
            }

            return $this->redirectToRoute('_assistant');
        }

        return $this->render('@AugiasMcp/Assistant/index.html.twig', [
            'history' => array_values(array_filter($history, static fn (mixed $m): bool => is_array($m) && is_string($m['content'] ?? null))),
            'company' => $company,
        ]);
    }
}
