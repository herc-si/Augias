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

namespace Augias\SaasBundle\Support;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\UserBundle\Security\CompanyAccess;
use Augias\UserBundle\Security\SupportPass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use function preg_replace;
use function str_contains;

/**
 * Nobody should be inside a company without it showing, on either side.
 *
 * The visitor sees whose company they are in, that they can only read, until
 * when, and the way out. Every member sees that the door is open, to whom and
 * until when — not only those who may close it: a member who did not know
 * would otherwise find out from the access log, afterwards.
 */
final readonly class SupportBannerListener
{
    public function __construct(
        private CompanyAccess $access,
        private SupportDesk $desk,
        private CompanySelector $companySelector,
        private CompanyRepository $companies,
        private Environment $twig,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (! $event->isMainRequest() || ! $request->isMethod('GET') || Response::HTTP_OK !== $response->getStatusCode()) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || ! str_contains($content, '<div class="page-wrapper">')) {
            return;
        }

        $banner = $this->banner();

        if (null === $banner) {
            return;
        }

        $response->setContent((string) preg_replace('/<div class="page-wrapper">/', '<div class="page-wrapper">' . $banner, $content, 1));
    }

    private function banner(): ?string
    {
        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid || ! $this->desk->isEnabled()) {
            return null;
        }

        $company = $this->companies->find($companyId);

        if (! $company instanceof Company) {
            return null;
        }

        $pass = $this->access->pass();

        if ($pass instanceof SupportPass) {
            return $this->twig->render('@AugiasSaas/support/_visitor_banner.html.twig', [
                'companyName' => (string) $company->getName(),
                'until' => $pass->until,
            ]);
        }

        if (null === $this->access->role()) {
            return null;
        }

        $open = $this->desk->openFor($company);

        if (! $open instanceof SupportRequest) {
            return null;
        }

        return $this->twig->render('@AugiasSaas/support/_member_banner.html.twig', [
            'request' => $open,
            'provider' => $this->desk->settings()->getProviderName(),
        ]);
    }
}
