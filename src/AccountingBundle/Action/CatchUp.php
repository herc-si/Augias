<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Action;

use Augias\AccountingBundle\Regime\RegimeInterface;
use Augias\AccountingBundle\Regime\RegimeRegistry;
use Augias\AccountingBundle\Service\AccountingProfileProvider;
use Augias\AccountingBundle\Service\BooksCatchUp;
use Augias\AccountingBundle\Service\CurrentCompany;
use Augias\CoreBundle\Entity\Company;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Takes into the books the documents dated before they were opened.
 *
 * Shows first what would be added, from the start of the financial year
 * unless another day is asked for, and writes it on confirmation. See
 * {@see BooksCatchUp}.
 */
final readonly class CatchUp
{
    private const string TOKEN_ID = 'accounting_catch_up';

    public function __construct(
        private BooksCatchUp $catchUp,
        private CurrentCompany $currentCompany,
        private AccountingProfileProvider $profileProvider,
        private RegimeRegistry $registry,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private RouterInterface $router,
        private Environment $twig,
    ) {
    }

    /**
     * @throws MathException
     */
    public function __invoke(Request $request, Session $session): Response
    {
        $company = $this->currentCompany->get();

        // No regime, no books to take anything into.
        if (! $company instanceof Company || ! $this->registry->forProfile($this->profileProvider->forCompany($company)) instanceof RegimeInterface) {
            return new RedirectResponse($this->router->generate('_accounting_index'));
        }

        $from = $this->requestedStart($request) ?? $this->catchUp->defaultStart($company);

        if ($request->isMethod('POST')) {
            if (! $this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, (string) $request->request->get('_token')))) {
                $session->getFlashBag()->add('danger', 'accounting.entry.flash.invalid_token');

                return new RedirectResponse($this->router->generate('_accounting_catch_up', ['from' => $from->format('Y-m-d')]));
            }

            $added = $this->catchUp->run($company, $from);

            $session->getFlashBag()->add('success', $added === 0 ? 'accounting.catch_up.flash.nothing' : 'accounting.catch_up.flash.added');

            return new RedirectResponse($this->router->generate('_accounting_index'));
        }

        return new Response($this->twig->render('@AugiasAccounting/Default/catch_up.html.twig', [
            'plan' => $this->catchUp->plan($company, $from),
            'tokenId' => self::TOKEN_ID,
        ]));
    }

    /**
     * The day asked for, from the query string or the confirmation form; a
     * date that does not parse is taken as no date at all.
     */
    private function requestedStart(Request $request): ?DateTimeImmutable
    {
        $value = (string) ($request->request->get('from') ?? $request->query->get('from', ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
