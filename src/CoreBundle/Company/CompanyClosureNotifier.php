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

namespace Augias\CoreBundle\Company;

use Augias\CoreBundle\Entity\Company;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Enum\CompanyPermission;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_filter;
use function array_map;
use function array_values;

/**
 * Writes to the people who can act on a company's closure — its owner and
 * administrators — when it is asked for, a week before, and once it is done.
 *
 * The banner inside the application only reaches someone who signs in; a
 * company closing unnoticed would lose documents its owner has to keep.
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyClosureTest
 */
final readonly class CompanyClosureNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<string> the addresses of those to write to
     */
    public function recipients(Company $company): array
    {
        $recipients = [];

        foreach ($company->getMemberships() as $membership) {
            assert($membership instanceof Membership);

            if ($membership->getRole()->can(CompanyPermission::ManageMembers)) {
                $recipients[] = (string) $membership->getUser()->getEmail();
            }
        }

        return array_values(array_filter($recipients));
    }

    /**
     * @param list<string>|null $recipients who to write to; read off the company when null — not possible once it is deleted
     */
    public function notify(Company $company, ClosureNotice $notice, ?array $recipients = null): void
    {
        $recipients ??= $this->recipients($company);

        if ([] === $recipients) {
            return;
        }

        $name = (string) $company->getName();
        $exportUrl = ClosureNotice::Deleted === $notice ? null : $this->urls->generate('_export_list', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = new CompanyClosureEmail(
            $notice,
            $name,
            $company->getClosesAt(),
            $exportUrl,
            $this->translator->trans('company.closing.email.' . $notice->value . '.subject', [
                '%company%' => $name,
                '%date%' => $company->getClosesAt()?->format('d/m/Y') ?? '',
            ]),
        );
        $email->to(...array_map(static fn (string $address): Address => new Address($address), $recipients));

        $this->mailer->send($email);
    }
}
