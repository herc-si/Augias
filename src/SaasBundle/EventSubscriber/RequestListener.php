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

namespace Augias\SaasBundle\EventSubscriber;

use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Repository\CompanyRepository;
use Augias\SaasBundle\Action\AbandonCompanyAction;
use Augias\SaasBundle\Feature\Feature;
use Augias\SaasBundle\Plan\FreePlanAllowance;
use Augias\SaasBundle\Service\TrialBanner;
use Augias\SaasBundle\Service\TrialBannerResolver;
use Augias\SaasBundle\Subscription\CoveredSubscriptionProvider;
use Augias\UserBundle\Entity\User;
use Psr\Clock\ClockInterface;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Enum\SubscriptionStatus;
use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;
use SolidWorx\Platform\SaasBundle\Repository\PlanRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use function assert;
use function in_array;

/**
 * @see \Augias\SaasBundle\Tests\EventSubscriber\RequestListenerTest
 */
final readonly class RequestListener implements EventSubscriberInterface
{
    private const array SKIPPED_ROUTES = [
        '_switch_company',
        '_view_quote_external',
        '_view_invoice_external',
        'billing_index',
        'saas_subscription_checkout',
        'saas_subscription_plans',
        'saas_subscription_choose',
        'saas_subscription_change',
        'saas_subscription_change_confirm',
        'saas_subscription_cancel_downgrade',
        'saas_company_abandon',
        // Offered on the pending, expired and cancelled pages themselves.
        'saas_subscription_cover',
        'saas_payment_success',

        // Debug routes
        '_wdt',
        '_wdt_stylesheet',
        '_profiler',
        '_profiler_search',
        '_profiler_search_bar',
        '_profiler_search_results',
        '_profiler_router',
    ];

    /**
     * Still open once the subscription has ended: the terms promise the data
     * can be taken out for ninety days, until it is deleted.
     */
    /**
     * Where a subscription is chosen, paid or changed. A company covered by
     * another's subscription has none of its own to manage: it is sent to its
     * subscription page, which names the company to manage it from.
     */
    private const array MANAGED_ROUTES = [
        'saas_subscription_checkout',
        'saas_subscription_plans',
        'saas_subscription_choose',
        'saas_subscription_change',
        'saas_subscription_change_confirm',
        'saas_subscription_cancel_downgrade',
        'saas_company_abandon',
    ];

    private const array EXPORT_ROUTES = [
        '_export_list',
        '_export_request',
        '_export_download',
    ];

    public function __construct(
        private CompanySelector $companySelector,
        private CompanyRepository $companyRepository,
        private SubscriptionProviderInterface $subscriptionManager,
        private PlanRepositoryInterface $planRepository,
        private Environment $twig,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private TrialBannerResolver $trialBannerResolver,
        private FreePlanAllowance $freePlanAllowance,
        private TranslatorInterface $translator,
        private PlanFeatureManager $planFeatures,
        private CoveredSubscriptionProvider $coverage,
        #[Autowire(env: 'AUGIAS_SAAS_ONBOARDING_COUPON_CODE')]
        private string $onboardingCouponCode = '',
        #[Autowire(env: 'int:AUGIAS_SAAS_ONBOARDING_COUPON_PERCENT')]
        private int $couponPercent = 30,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onRequest',
            ResponseEvent::class => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->sendCoveredCompanyHome($event)) {
            return;
        }

        $subscription = $this->getSubscription($event->getRequest());

        if (! $subscription instanceof Subscription) {
            return;
        }

        switch ($subscription->getStatus()) {
            case SubscriptionStatus::PENDING:
                $company = $subscription->getSubscriber();
                assert($company instanceof Company);

                $event->setResponse(
                    new Response(
                        $this->twig->render('@AugiasSaas/subscription/pending.html.twig', [
                            'subscription' => $subscription,
                            'plans' => $this->freePlanAllowance->plansFor($company, $this->planRepository->findAllOrdered()),
                            'freePlanTaken' => ! $this->freePlanAllowance->allows($company),
                            'canAbandon' => $this->canAbandon($company, $subscription),
                        ]),
                    )
                );
                break;
            case SubscriptionStatus::PAUSED:
                $event->setResponse(
                    new Response(
                        $this->twig->render('@AugiasSaas/subscription/paused.html.twig', [
                            'subscription' => $subscription,
                        ]),
                    )
                );
                break;
            case SubscriptionStatus::CANCELLED:
            case SubscriptionStatus::EXPIRED:
                if ($subscription->getEndDate() > $this->clock->now() || $this->isExport($event->getRequest())) {
                    return;
                }

                $event->setResponse(
                    new Response(
                        $this->twig->render('@AugiasSaas/subscription/cancelled.html.twig', [
                            'subscription' => $subscription,
                        ]),
                    )
                );
                break;
            case SubscriptionStatus::TRIAL:
                if ($subscription->getEndDate() <= $this->clock->now() && ! $this->isExport($event->getRequest())) {
                    $event->setResponse(
                        new Response(
                            $this->twig->render('@AugiasSaas/subscription/trial_expired.html.twig', [
                                'subscription' => $subscription,
                                'coupon_code' => $this->onboardingCouponCode,
                                'coupon_percent' => $this->couponPercent,
                                // The page lists what the plan tried keeps on
                                // paying for: an entry plan has neither.
                                'has_online_payments' => $this->planFeatures->hasFeature($subscription->getPlan(), Feature::OnlinePayments->value),
                                'has_recurring_invoices' => $this->planFeatures->hasFeature($subscription->getPlan(), Feature::RecurringInvoices->value),
                            ]),
                        )
                    );
                }

                break;
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (! $request->isMethod('GET') || $response->getStatusCode() !== Response::HTTP_OK) {
            return;
        }

        $subscription = $this->getSubscription($request);
        if (! $subscription instanceof Subscription) {
            return;
        }

        $banner = $this->trialBannerResolver->resolve($subscription);
        if (! $banner instanceof TrialBanner) {
            return;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return;
        }

        $html = $this->twig->render('@AugiasSaas/_alert_banner.html.twig', [
            'type' => $banner->type,
            'icon' => $banner->icon,
            'title' => $this->translator->trans($banner->titleKey, $banner->params),
            'message' => $this->translator->trans($banner->messageKey, $banner->params),
            'cta_label' => $this->translator->trans($banner->ctaLabelKey, $banner->params),
            'cta_url' => $this->urlGenerator->generate('saas_subscription_checkout'),
            'code' => $banner->code,
        ]);

        $content = preg_replace_callback(
            '/<div class="page-wrapper">/',
            static fn (): string => '<div class="page-wrapper">' . $html,
            $content,
            1
        );

        $response->setContent($content);
    }

    private function isExport(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), self::EXPORT_ROUTES, true);
    }

    private function canAbandon(Company $company, Subscription $subscription): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && AbandonCompanyAction::canBeAbandoned($company, $user, $subscription);
    }

    private function sendCoveredCompanyHome(RequestEvent $event): bool
    {
        if (! in_array($event->getRequest()->attributes->get('_route'), self::MANAGED_ROUTES, true)) {
            return false;
        }

        if (! $this->security->getUser() instanceof UserInterface) {
            return false;
        }

        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->companyRepository->find($companyId) : null;

        if (! $company instanceof Company || ! $this->coverage->hostOf($company) instanceof Company) {
            return false;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('billing_index')));

        return true;
    }

    private function getSubscription(Request $request): ?Subscription
    {
        if ($request->attributes->get('_stateless') === true) {
            return null;
        }

        if (in_array($request->attributes->get('_route'), self::SKIPPED_ROUTES, true)) {
            return null;
        }

        if (! $this->security->getUser() instanceof UserInterface) {
            return null;
        }

        $companyId = $this->companySelector->getCompany();

        if (! $companyId instanceof Ulid) {
            return null;
        }

        $company = $this->companyRepository->find($companyId);

        if (! $company instanceof Company) {
            return null;
        }

        return $this->subscriptionManager->getSubscriptionFor($company);
    }
}
