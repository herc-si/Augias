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

namespace Augias\SaasBundle\Action\Support;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Feature\Feature;
use Augias\SaasBundle\Form\SupportRequestType;
use Augias\SaasBundle\Support\SupportDesk;
use Augias\UserBundle\Entity\User;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;
use function in_array;

/**
 * Asking the people who run the service for help, and seeing every request
 * the company has made — who took it, until when the door stays open, and
 * what was done.
 */
final class SupportAction extends AbstractController
{
    /**
     * Offered first: a day is long enough for someone to get to it, short
     * enough that nobody forgets the door is open.
     */
    private const int PREFERRED_HOURS = 24;

    public function __construct(
        private readonly SupportDesk $desk,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
        private readonly FeatureGate $featureGate,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $settings = $this->desk->settings();
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;
        $user = $this->getUser();

        if (! $settings->isEnabled() || ! $company instanceof Company || ! $user instanceof User) {
            throw new NotFoundHttpException();
        }

        if (! $this->featureGate->isEnabled(Feature::SupportAccess->value, $company)) {
            return $this->render('@AugiasSaas/support/gated.html.twig');
        }

        $open = $this->desk->openFor($company);
        $durations = $settings->getDurations();
        $form = $this->createForm(SupportRequestType::class, null, [
            'durations' => $durations,
            'default_hours' => in_array(self::PREFERRED_HOURS, $durations, true) ? self::PREFERRED_HOURS : $durations[0],
        ]);

        if (null === $open) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{message: string, hours: int} $data */
                $data = $form->getData();
                $this->desk->open($company, $user->getUserIdentifier(), $data['message'], $data['hours']);
                $this->addFlash('success', 'support.flash.requested');

                return $this->redirectToRoute('_support');
            }
        }

        return $this->render('@AugiasSaas/support/index.html.twig', [
            'form' => null === $open ? $form : null,
            'open' => $open,
            'requests' => $this->desk->forCompany($company),
            'provider' => $settings->getProviderName(),
            'now' => $this->desk->now(),
        ]);
    }
}
