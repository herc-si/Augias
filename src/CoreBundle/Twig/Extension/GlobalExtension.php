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

namespace Augias\CoreBundle\Twig\Extension;

use Augias\CoreBundle\AugiasCoreBundle;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Company\ResolvedHost;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Listener\HostRoutingListener;
use Augias\CoreBundle\Pdf\Generator;
use Augias\MoneyBundle\Calculator;
use Augias\SettingsBundle\SystemConfig;
use Carbon\Carbon;
use DateTimeInterface;
use Locale;
use Override;
use SolidWorx\Toggler\ToggleInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Ulid;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use function implode;

/**
 * @see \Augias\CoreBundle\Tests\Twig\Extension\GlobalExtensionTest
 */
class GlobalExtension extends AbstractExtension implements GlobalsInterface
{
    private const string DEFAULT_LOGO = 'png|iVBORw0KGgoAAAANSUhEUgAAAGAAAABgCAMAAADVRocKAAAAb1BMVEUAAAAeSXYjTXlohqYpUn2vwdRff6Dd5/Gmuczk7fZJbZLW4u2YrsXT3+uCnLc0W4TJ1uR3k7AuVoG4yNpTdJeNpb49Y4rm6/FHU2hcZHbKvr3bz8uWk5syTnC1bEbGckPJcT/KglvMf1Th3+E2UXOSuceVAAAAAXRSTlMAQObYZgAAAsxJREFUaN7tmuuSojAQhRVREkQhgi4zO7qLO+//jFsOE2kgl9NArNqqPT8l6Y8kdJ9IWK2GWs/SyqN50b2MJcI7EEuFtyCWDG9CLB1/SFg+fp8QIj4lMDpF0RQCo89mMwHA6BJvtzGfwOixS5IdG8DoEAkpBXsVGO3TvZT7lAlgNF9nUkqZMYfAGcDhATgwh8BofZRfOrIAjMZ50QKKnENgtFXyWyoM4FRqQHkKAtgkGpAw6gUOeCSZlsDrBQ6okg6QVMsDokwS4ckGA9ok08KTDQacZU/npQGXog8oLgsDlBwITTYQkJdDQAnWCxCwkSOByYYB4u0YAJozBtglYwBozhCAVImyWwzMnCHAw4r1zHergZkzAiBVoszJ8wTVCwRAqoSiGQHVCwRw7OUvyWnEnAFA3kX8qkBdVULMGQAM56Q/Y/MBp+Gq0jX3m7MfQKz428c6b/vhrxdeQNwlmXZiw08zAJVhLzEe1HQAnfDn3caMZPMBSJUgj0z3YHnrhQ9gfuiHqTEdYEvbfnLPANgKDwGrOQAyFVn/SmacOjbAXvypRUwHxHb7oiYXTwZ0Vjw2YNc1FEDucryFIBsNpzm7AO55Bs3ZBXCXZVrGJwCiun57/6n1URv08bz8/lbXERNwvf1i6XZlA36z9B/wDwLS5v5Q0zT3m0P3pm11b1IeIMr+aInPq1Wf4tnMZs4WgNmKx/KbswWA7j/95mwG4DtorzmbAcrXzXQrCgfYrXgsnzkbAZz/YT5zNgFizj9JnzmbAKjdtqrcrQ0ApxUbxus2ZxOgEnvXrA6lV2wvKgywXkfpuZDw68vWnA9ZalwvW7G7qAJ+J6SkLI62fLHvKnIlwFdCuVD2ls6NFxbf3TDQCRcBBCYEO6R7JSAoIeBBJgEEJAQ9iyWAYITAx8kTD5QnxQ9/5B7+o4Hwnz2E/3DjBZ+evODjmQUYo3B/AeymfOqEuYoPAAAAAElFTkSuQmCC';

    public function __construct(
        private readonly Calculator $calculator,
        private readonly Generator $pdfGenerator,
        private readonly SystemConfig $systemConfig,
        private readonly RequestStack $requestStack,
        private readonly CompanySelector $companySelector,
        private readonly ?string $installed,
        private readonly ToggleInterface $toggler,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException
     */
    public function getGlobals(): array
    {
        return [
            'query' => $this->getQuery(),
            // Hide the version in SaaS mode: hosted deployments often run dev builds,
            // and exposing "3.1.x-dev" in footers/emails looks unprofessional
            'app_version' => $this->toggler->isActive('saas_enabled') ? null : AugiasCoreBundle::VERSION,
            'app_name' => AugiasCoreBundle::APP_NAME,
        ];
    }

    /**
     * @return array<string, string>
     *
     * @throws ServiceCircularReferenceException|ServiceNotFoundException
     */
    protected function getQuery(): array
    {
        $request = $this->requestStack->getCurrentRequest();

        if (! $request instanceof Request) {
            return [];
        }

        $params = array_merge($request->query->all(), $request->attributes->all());

        foreach (array_keys($params) as $key) {
            if (str_starts_with($key, '_')) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * @return TwigFilter[]
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('percentage', $this->calculator->calculatePercentage(...)),

            new TwigFilter('diff', $this->dateDiff(...)),

            new TwigFilter('md5', 'md5'),

            new TwigFilter('repeat', fn (string $string, int $times): string => str_repeat($string, $times)),
        ];
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('icon', $this->displayIcon(...), ['is_safe' => ['html']]),

            new TwigFunction('app_logo', $this->displayAppLogo(...), ['is_safe' => ['html']]),

            new TwigFunction('company_name', function (): string {
                if ($this->companySelector->getCompany() instanceof Ulid) {
                    return $this->systemConfig->get('system/company/company_name') ?? AugiasCoreBundle::APP_NAME;
                }

                return AugiasCoreBundle::APP_NAME;
            }),

            new TwigFunction('company_id', $this->companySelector->getCompany(...)),

            new TwigFunction('can_print_pdf', $this->pdfGenerator->canPrintPdf(...)),

            new TwigFunction('is_custom_domain', $this->isCustomDomain(...)),
        ];
    }

    public function isCustomDomain(): bool
    {
        $request = $this->requestStack->getMainRequest();
        $resolved = $request?->attributes->get(HostRoutingListener::REQUEST_ATTR);

        return $resolved instanceof ResolvedHost && $resolved->isCustomDomain();
    }

    /**
     * @throws InvalidArgumentException|ServiceCircularReferenceException|ServiceNotFoundException
     */
    public function displayAppLogo(string $width = 'auto', ?Company $company = null, bool $showDefault = false, bool $showOnlyAppIcon = false): string
    {
        $logo = $showDefault ? self::DEFAULT_LOGO : null;

        if ($this->installed && ! $showOnlyAppIcon) {
            $logo = $this->companySelector->getCompany() instanceof Ulid ? $this->systemConfig->get('system/company/logo', $company) : self::DEFAULT_LOGO;

            if (null === $logo) {
                $logo = $showDefault ? self::DEFAULT_LOGO : null;
            }
        }

        if (null === $logo) {
            return '';
        }

        [$type, $logo] = explode('|', $logo);

        return sprintf(
            '<img src="data:image/%s;base64,%s" class="navbar-brand-image m-2" width="%s"/>',
            htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $width, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @param list<string> $options
     */
    public function displayIcon(string $iconName, array $options = []): string
    {
        $class = sprintf('fa fa-%s', $iconName);

        if ([] !== $options) {
            $class .= ' ' . implode(' ', $options);
        }

        return sprintf('<i class="%s"></i>', $class);
    }

    /**
     * Returns a human-readable diff for dates.
     */
    public function dateDiff(DateTimeInterface $date): string
    {
        // Per call rather than through Carbon::setLocale(): the locale also
        // decides which day a week starts on, and setting it globally moved the
        // end date of every weekly recurring invoice by a day. The suite caught
        // it — see RecurringScheduleTest.
        return Carbon::instance($date)->locale(Locale::getDefault())->diffForHumans();
    }
}
