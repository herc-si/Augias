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
use Augias\McpBundle\Assistant\AssistantAccess;
use Augias\McpBundle\Assistant\AssistantTranscriber;
use Augias\McpBundle\Assistant\AssistantUnavailable;
use Augias\McpBundle\Assistant\CurrentCompany;
use Augias\UserBundle\Security\CompanyAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use function explode;
use function in_array;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * What was said into the assistant's microphone, as text, for the panel to
 * send as a question. The recording is passed on and not kept.
 *
 * @see \Augias\McpBundle\Tests\Assistant\AssistantPanelTest
 */
#[Route(path: '/assistant/transcribe', name: '_assistant_transcribe', methods: ['POST'])]
final class AssistantTranscribeAction extends AbstractController
{
    /** A minute of speech is far below this, whatever the format. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly AssistantTranscriber $transcriber,
        private readonly CurrentCompany $currentCompany,
        private readonly TranslatorInterface $translator,
        private readonly AssistantAccess $access,
        private readonly CompanyAccess $companyAccess,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany->get();

        if (! $this->assistant->isAvailable() || ! $this->transcriber->isConfigured() || $company === null || $this->companyAccess->pass() !== null) {
            throw new NotFoundHttpException();
        }

        // Spoken or typed, a question needs the assistant on and the month's quota not used up.
        if (! $this->access->state($company)->isReady()) {
            return new JsonResponse(['error' => $this->translator->trans('assistant.unavailable')], Response::HTTP_FORBIDDEN);
        }

        if (! $this->isCsrfTokenValid(AssistantMessagesAction::CSRF, (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('assistant.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        $audio = $request->files->get('audio');

        if (! $audio instanceof UploadedFile || ! $audio->isValid() || $audio->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => $this->translator->trans('assistant.voice_unreadable')], Response::HTTP_BAD_REQUEST);
        }

        // What the browser says it recorded, without its codecs parameter.
        $type = strtolower(explode(';', (string) $audio->getClientMimeType())[0]);

        if (! str_starts_with($type, 'audio/') && ! in_array($type, ['video/webm', 'video/mp4'], true)) {
            return new JsonResponse(['error' => $this->translator->trans('assistant.voice_unreadable')], Response::HTTP_BAD_REQUEST);
        }

        $extension = match (true) {
            str_contains($type, 'mp4'), str_contains($type, 'm4a'), str_contains($type, 'aac') => 'mp4',
            str_contains($type, 'ogg') => 'ogg',
            str_contains($type, 'wav') => 'wav',
            str_contains($type, 'mpeg') => 'mp3',
            default => 'webm',
        };

        try {
            $text = $this->transcriber->transcribe($audio->getPathname(), 'voice.' . $extension, $type, $request->getLocale());
        } catch (AssistantUnavailable) {
            return new JsonResponse(['error' => $this->translator->trans('assistant.voice_unavailable')], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['text' => $text]);
    }
}
