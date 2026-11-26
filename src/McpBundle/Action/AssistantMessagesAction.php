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
use Augias\McpBundle\Assistant\AssistantAccess;
use Augias\McpBundle\Assistant\AssistantAccessState;
use Augias\McpBundle\Assistant\AssistantAccessStatus;
use Augias\McpBundle\Assistant\AssistantAnswer;
use Augias\McpBundle\Assistant\AssistantConversation;
use Augias\McpBundle\Assistant\AssistantJournal;
use Augias\McpBundle\Assistant\AssistantToolbox;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Augias\McpBundle\Assistant\CurrentCompany;
use Augias\McpBundle\Enum\AssistantEventKind;
use Augias\UserBundle\Security\CompanyAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use function array_map;
use function ignore_user_abort;
use function implode;
use function mb_strimwidth;
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
        private readonly AssistantAccess $access,
        private readonly AssistantJournal $journal,
        private readonly CompanyAccess $companyAccess,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany->get();

        // Not for someone let in to help: the company's questions, its quota, its data sent out.
        if (! $this->assistant->isAvailable() || ! $company instanceof Company || $this->companyAccess->pass() !== null) {
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

        if ($request->request->has('activate') || $request->request->has('deactivate')) {
            return $this->switch($company, $request->request->has('activate'));
        }

        if ($this->conversation->isPending($company)) {
            return $this->state($company, $this->translator->trans('assistant.busy'), Response::HTTP_CONFLICT);
        }

        if ($request->request->has('confirm') || $request->request->has('cancel')) {
            return $this->settle($request, $company);
        }

        if (! $this->access->state($company)->isReady()) {
            return $this->state($company, null, Response::HTTP_FORBIDDEN);
        }

        $question = mb_substr(trim((string) $request->request->get('question')), 0, self::MAX_QUESTION);

        if ($question === '') {
            return $this->state($company);
        }

        $pending = $this->conversation->action($company);

        if ($pending !== null) {
            // Asking something else leaves the change unmade.
            $this->conversation->answer($company, $this->translator->trans('assistant.abandoned', ['%action%' => $this->summary($pending)]));
        }

        $history = $this->conversation->messages($company);
        $this->conversation->ask($company, $question);
        $this->conversation->release();

        // Leaving the page must not lose the answer: it is written down all the same.
        ignore_user_abort(true);

        try {
            $answer = $this->assistant->ask($question, (string) $company->getName(), $history);
            $this->journal->record(AssistantEventKind::Question);
            $this->record($company, $answer);
        } catch (AssistantUnavailable) {
            $this->conversation->withdraw($company);

            return $this->state($company, $this->translator->trans('assistant.unavailable'), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (Throwable $e) {
            $this->failed($company, $e);
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
            $this->journal->record(AssistantEventKind::Cancelled, $proposal->tool);
            // Said with what it was: the model reads this in the history,
            // and took a request cancelled as one still to do (Rémi,
            // 26/11/2026: the next quote went to the name asked before).
            $this->conversation->answer($company, $this->translator->trans('assistant.cancelled', ['%action%' => $this->summary($this->preview->describe($proposal->tool, $proposal->arguments))]));

            return $this->state($company);
        }

        if (! $company->isAssistantEnabled()) {
            // Turned off since it was proposed: nothing is made.
            $this->conversation->answer($company, $this->translator->trans('assistant.abandoned', ['%action%' => $this->summary($this->preview->describe($proposal->tool, $proposal->arguments))]));

            return $this->state($company, null, Response::HTTP_FORBIDDEN);
        }

        $this->conversation->release();
        ignore_user_abort(true);

        try {
            $outcome = $this->toolbox->run($proposal->tool, $proposal->arguments);
        } catch (Throwable $e) {
            $this->failed($company, $e);

            return $this->state($company);
        }
        $failed = str_starts_with($outcome, 'Error:');
        $this->logger->info('Assistant made a confirmed change.', ['tool' => $proposal->tool, 'failed' => $failed]);

        if ($failed) {
            // Why it was refused once confirmed: what to check before proposing next time.
            $this->logger->warning('A change the person confirmed was refused.', ['tool' => $proposal->tool, 'reason' => mb_substr($outcome, 0, 500)]);
        }
        $this->journal->record(AssistantEventKind::Confirmed, $proposal->tool, $failed);

        try {
            $this->record($company, $this->assistant->resume($proposal, $outcome, (string) $company->getName()));
        } catch (AssistantUnavailable) {
            // Made or refused all the same: said plainly, without the model.
            $this->conversation->answer($company, $this->translator->trans($failed ? 'assistant.action_failed' : 'assistant.action_done'));
        } catch (Throwable $e) {
            $this->failed($company, $e);
        }

        return $this->state($company);
    }

    /**
     * The change in a few words: what it does and its first details, « Nouveau devis (Client : Dupont SARL) ».
     *
     * @param array{title: string, details: list<array{label: string, value: string}>} $preview
     */
    private function summary(array $preview): string
    {
        $details = array_map(
            static fn (array $detail): string => $detail['label'] . ' : ' . mb_strimwidth($detail['value'], 0, 60, '…'),
            \array_slice($preview['details'], 0, 2),
        );

        return $preview['title'] . ($details === [] ? '' : ' (' . implode(', ', $details) . ')');
    }

    /**
     * Whatever went wrong, the question gets an answer: without one it
     * stayed pending and the page waited for good (Rémi, 26/11/2026: a
     * contact refused by the database, after the quote was made).
     */
    private function failed(Company $company, Throwable $e): void
    {
        $this->logger->error('The assistant stopped on an error.', ['exception' => $e]);
        $this->conversation->answer($company, $this->translator->trans('assistant.failed'));
    }

    private function record(Company $company, AssistantAnswer $answer): void
    {
        if ($answer->proposal !== null) {
            $this->conversation->propose($company, $answer->proposal, $this->preview->describe($answer->proposal->tool, $answer->proposal->arguments), $answer->text);

            return;
        }

        $this->conversation->answer($company, $answer->gaveUp || $answer->text === '' ? $this->translator->trans('assistant.gave_up') : $answer->text);
    }

    private function switch(Company $company, bool $on): Response
    {
        if (! $this->access->canSwitch()) {
            return $this->state($company, $this->translator->trans('assistant.owner_only'), Response::HTTP_FORBIDDEN);
        }

        if ($on) {
            $company->enableAssistant($this->clock->now(), (string) $this->getUser()?->getUserIdentifier());
        } else {
            $company->disableAssistant();
            $this->conversation->reset($company);
        }

        $this->entityManager->flush();
        $this->journal->record($on ? AssistantEventKind::Activated : AssistantEventKind::Deactivated);

        return $this->state($company);
    }

    private function state(Company $company, ?string $error = null, int $status = Response::HTTP_OK): JsonResponse
    {
        $access = $this->access->state($company);

        return new JsonResponse([
            'messages' => $this->conversation->messages($company),
            'pending' => $this->conversation->isPending($company),
            'action' => $this->conversation->action($company),
            'notice' => $this->notice($access),
            'usage' => $access->limit !== null && $access->status === AssistantAccessStatus::Ready
                ? $this->translator->trans('assistant.usage', ['%used%' => $access->used, '%limit%' => $access->limit])
                : null,
            'canDeactivate' => $company->isAssistantEnabled() && $this->access->canSwitch(),
            'error' => $error,
        ], $status);
    }

    /**
     * Why the person cannot ask now, and what they can do about it.
     *
     * @return array{text: string, button: array{label: string, action?: string, href?: string}|null}|null
     */
    private function notice(AssistantAccessState $access): ?array
    {
        return match ($access->status) {
            AssistantAccessStatus::Ready => null,
            AssistantAccessStatus::NotInPlan => [
                'text' => $this->translator->trans('assistant.notice.not_in_plan'),
                'button' => ['label' => $this->translator->trans('assistant.see_plans'), 'href' => $this->generateUrl('saas_subscription_plans', [], UrlGeneratorInterface::ABSOLUTE_PATH)],
            ],
            AssistantAccessStatus::Off => $this->access->canSwitch()
                ? ['text' => $this->translator->trans('assistant.notice.off_owner'), 'button' => ['label' => $this->translator->trans('assistant.activate'), 'action' => 'activate']]
                : ['text' => $this->translator->trans('assistant.notice.off_member'), 'button' => null],
            AssistantAccessStatus::QuotaReached => [
                'text' => $this->translator->trans('assistant.notice.quota_reached', [
                    '%limit%' => $access->limit,
                    '%date%' => $this->clock->now()->modify('first day of next month')->format('d/m/Y'),
                ]),
                'button' => null,
            ],
        };
    }
}
