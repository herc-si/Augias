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

use Augias\CoreBundle\Entity\SupportRequest;
use Augias\CoreBundle\Entity\SupportSettings;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Enum\CompanyPermission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_unique;

/**
 * Tells each side when a request for help moves.
 *
 * The company hears it from whoever may manage who has access — the same
 * people who could have asked — and from whoever asked, if they are no longer
 * among them: letting someone in is exactly the kind of thing they must not
 * learn about after the fact.
 */
final readonly class SupportMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        /**
         * Where the company works. A request is also taken from the operator's
         * tooling, which may answer on a host of its own: a link built from
         * that request would send the company there.
         */
        #[Autowire(env: 'AUGIAS_APPLICATION_URL')]
        private string $applicationUrl = '',
    ) {
    }

    public function requested(SupportRequest $request, SupportSettings $settings): void
    {
        $to = $settings->getNotifyEmail();

        if (null === $to) {
            return;
        }

        $this->send(SupportNotice::Requested, $request, $settings, [$to], null);
    }

    public function accepted(SupportRequest $request, SupportSettings $settings): void
    {
        $this->send(SupportNotice::Accepted, $request, $settings, $this->company($request), $this->link());
    }

    public function resolved(SupportRequest $request, SupportSettings $settings): void
    {
        $this->send(SupportNotice::Resolved, $request, $settings, $this->company($request), $this->link());
    }

    private function link(): string
    {
        $base = rtrim(trim($this->applicationUrl), '/');

        return '' === $base
            ? $this->urls->generate('_support', [], UrlGeneratorInterface::ABSOLUTE_URL)
            : $base . $this->urls->generate('_support');
    }

    /**
     * @return list<string>
     */
    private function company(SupportRequest $request): array
    {
        $recipients = [$request->getRequestedBy()];

        foreach ($request->getCompany()->getMemberships() as $membership) {
            if ($membership instanceof Membership && $membership->getRole()->can(CompanyPermission::ManageMembers)) {
                $recipients[] = (string) $membership->getUser()->getEmail();
            }
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    /**
     * @param list<string> $recipients
     */
    private function send(SupportNotice $notice, SupportRequest $request, SupportSettings $settings, array $recipients, ?string $url): void
    {
        if ([] === $recipients) {
            return;
        }

        $provider = $settings->getProviderName() ?? $this->translator->trans('support.provider_fallback');

        $email = new SupportEmail(
            $notice,
            $request,
            $provider,
            $url,
            $this->translator->trans('support.email.' . $notice->value . '.subject', [
                '%company%' => (string) $request->getCompany()->getName(),
                '%provider%' => $provider,
            ]),
        );
        $email->to(...array_map(static fn (string $address): Address => new Address($address), $recipients));

        $this->mailer->send($email);
    }
}
