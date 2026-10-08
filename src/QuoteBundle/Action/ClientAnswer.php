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

use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Enum\DocumentActivityType;
use Augias\QuoteBundle\Entity\Quote;
use Augias\QuoteBundle\Model\Graph;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * The client's answer to a quote, from the link they were sent: accepted under
 * the name they type and a box they tick ("bon pour accord"), or declined with
 * their reason if they give one.
 *
 * What is kept as their word goes in the quote's history: the name, the time,
 * the browser and the address the answer came from. Accepting then does what
 * accepting from inside the app does: the company is told, and the quote waits
 * for "Create the invoice".
 */
final readonly class ClientAnswer
{
    public const string ACCEPT = 'accept';

    public const string DECLINE = 'decline';

    private const int NAME_LENGTH = 120;

    private const int REASON_LENGTH = 255;

    public function __construct(
        private ManagerRegistry $doctrine,
        private CompanySelector $companySelector,
        private EmailVerificationGateInterface $emailVerificationGate,
        private WorkflowInterface $quoteStateMachine,
        private DocumentActivityRecorder $activity,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public static function csrfTokenId(Quote $quote): string
    {
        return 'quote_answer_' . $quote->getUuid()->toString();
    }

    public function __invoke(Request $request, string $uuid): RedirectResponse
    {
        $quote = $this->doctrine->getRepository(Quote::class)->findOneBy(['uuid' => $uuid]);

        if (! $quote instanceof Quote || $this->emailVerificationGate->isCompanyGated($quote->getCompany())) {
            throw new NotFoundHttpException('No such document.');
        }

        $this->companySelector->switchCompany($quote->getCompany()->getId());

        $back = new RedirectResponse($this->urlGenerator->generate('_view_quote_external', ['uuid' => $uuid]));

        if (! $this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($quote), (string) $request->request->get('_token')))) {
            return $this->flash($request, $back, 'danger', 'quote.client_answer.flash.expired_page');
        }

        $answer = (string) $request->request->get('answer');
        $transition = match ($answer) {
            self::ACCEPT => Graph::TRANSITION_ACCEPT,
            self::DECLINE => Graph::TRANSITION_DECLINE,
            default => null,
        };

        if (null === $transition || ! $this->quoteStateMachine->can($quote, $transition)) {
            return $this->flash($request, $back, 'warning', 'quote.client_answer.flash.already_answered');
        }

        if (self::ACCEPT === $answer && $quote->isExpiredOn($this->clock->now())) {
            return $this->flash($request, $back, 'warning', 'quote.client_answer.flash.expired');
        }

        $name = trim((string) $request->request->get('name'));

        if (self::ACCEPT === $answer) {
            if ('' === $name || mb_strlen($name) > self::NAME_LENGTH || ! $request->request->getBoolean('agree')) {
                return $this->flash($request, $back, 'danger', 'quote.client_answer.flash.incomplete');
            }

            $detail = $name;
            $type = DocumentActivityType::ClientAccepted;
        } else {
            $reason = trim((string) $request->request->get('reason'));
            $detail = '' === $reason ? null : mb_substr($reason, 0, self::REASON_LENGTH);
            $type = DocumentActivityType::ClientDeclined;
        }

        // The word first, then what follows from it: the history keeps the
        // answer even if turning the quote into an invoice were to fail.
        $this->activity->record(
            $quote,
            $quote->getCompany(),
            $type,
            $detail,
            userAgent: $request->headers->get('User-Agent'),
            ipAddress: $request->getClientIp(),
        );

        $this->quoteStateMachine->apply($quote, $transition);

        return $this->flash($request, $back, 'success', self::ACCEPT === $answer ? 'quote.client_answer.flash.accepted' : 'quote.client_answer.flash.declined');
    }

    private function flash(Request $request, RedirectResponse $response, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        return $response;
    }
}
