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

namespace Augias\ClientBundle\Action;

use Augias\ClientBundle\Import\ClientCsvReader;
use Augias\ClientBundle\Import\ClientImporter;
use Augias\ClientBundle\Import\UnreadableClientFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use function file_get_contents;
use function is_string;

/**
 * Brings a client list in from a spreadsheet export, in two steps: the file
 * is read and the user shown what it would create, then the same file is
 * imported on their say-so.
 *
 * The file waits in the session between the two, so what is imported is what
 * was previewed and nothing is uploaded twice.
 */
final readonly class Import
{
    /** 2 000 lines of a client list weigh a few hundred kilobytes. */
    private const int MAX_BYTES = 1024 * 1024;

    private const string PENDING = 'client_import_pending';

    public function __construct(
        private ClientImporter $importer,
        private CsrfTokenManagerInterface $csrf,
        private RouterInterface $router,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request, Session $session): Response
    {
        if (! $request->isMethod('POST')) {
            $session->remove(self::PENDING);

            return $this->page();
        }

        $back = new RedirectResponse($this->router->generate('_clients_import'));

        if (! $this->csrf->isTokenValid(new CsrfToken('client_import', (string) $request->request->get('_token')))) {
            $session->getFlashBag()->add('danger', 'client.import.error.invalid_token');

            return $back;
        }

        $confirmed = $request->request->getBoolean('confirm');
        $content = $confirmed ? $session->get(self::PENDING) : $this->uploaded($request);

        if (! is_string($content)) {
            $session->getFlashBag()->add('danger', $confirmed ? 'client.import.error.expired' : 'client.import.error.no_file');

            return $back;
        }

        try {
            $report = $this->importer->import($content, dryRun: ! $confirmed);
        } catch (UnreadableClientFile $unreadable) {
            $session->remove(self::PENDING);
            $session->getFlashBag()->add('danger', 'client.import.error.' . $unreadable->reason);

            return $back;
        }

        if ($confirmed) {
            $session->remove(self::PENDING);
        } else {
            $session->set(self::PENDING, $content);
        }

        return $this->page(['report' => $report, 'imported' => $confirmed]);
    }

    private function uploaded(Request $request): ?string
    {
        $file = $request->files->get('file');

        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            return null;
        }

        $content = file_get_contents($file->getPathname());

        return false === $content ? null : $content;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(array $context = []): Response
    {
        return new Response($this->twig->render('@AugiasClient/Default/import.html.twig', $context + [
            'report' => null,
            'imported' => false,
            'columns' => ClientCsvReader::templateHeader(),
            'maxRows' => ClientCsvReader::MAX_ROWS,
        ]));
    }
}
