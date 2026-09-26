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
use Augias\CoreBundle\Repository\CompanyRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use function sprintf;

/**
 * Closing a company: scheduled, called off, and — once the date has passed —
 * carried out.
 *
 * The owner asks; the company then stays for GRACE_DAYS, read-only, so its
 * data can be taken out and a mistake undone. After that it is deleted with
 * everything in it, issued invoices included. Those are otherwise kept
 * whatever happens (see IssuedDocumentRetentionListener); the one exception
 * is this, which the guard learns from CompanyPurgeContext.
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyClosureTest
 */
final class CompanyClosure
{
    public const int GRACE_DAYS = 30;

    /** How long before the date the last reminder goes out. */
    public const int REMINDER_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companies,
        private readonly ClockInterface $clock,
        private readonly ?CompanyClosureNotifier $notifier = null,
        private readonly CompanyPurgeContext $context = new CompanyPurgeContext(),
    ) {
    }

    public function schedule(Company $company): DateTimeImmutable
    {
        $closesAt = DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('+%d days', self::GRACE_DAYS));
        $company->scheduleClosure($closesAt);
        $this->entityManager->flush();

        $this->notifier?->notify($company, ClosureNotice::Scheduled);

        return $closesAt;
    }

    public function cancel(Company $company): void
    {
        $company->cancelClosure();
        $this->entityManager->flush();
    }

    /**
     * Reminds, once, the companies closing within REMINDER_DAYS.
     *
     * @return int how many were reminded
     */
    public function remindDue(): int
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $reminded = 0;

        foreach ($this->companies->findClosingBefore($now->modify(sprintf('+%d days', self::REMINDER_DAYS))) as $company) {
            if (null !== $company->getClosureRemindedAt() || $company->getClosesAt() <= $now) {
                continue;
            }

            $this->notifier?->notify($company, ClosureNotice::Reminder);
            $company->markClosureReminded($now);
            ++$reminded;
        }

        $this->entityManager->flush();

        return $reminded;
    }

    /**
     * Deletes every company whose closure date has passed.
     *
     * @return list<string> the names of the companies deleted
     */
    public function purgeDue(): array
    {
        $deleted = [];

        // Archived documents go with the company too: hidden by the filter,
        // they would escape the cascade and, on SQLite, stay behind.
        $filters = $this->entityManager->getFilters();
        $archivable = $filters->isEnabled('archivable');

        if ($archivable) {
            $filters->disable('archivable');
        }

        foreach ($this->companies->findClosingBefore(DateTimeImmutable::createFromInterface($this->clock->now())) as $company) {
            // Read before the memberships go with the company.
            $recipients = $this->notifier?->recipients($company) ?? [];

            $this->context->during($company, fn () => $this->companies->deleteCompany($company->getId()));

            $deleted[] = (string) $company->getName();
            $this->notifier?->notify($company, ClosureNotice::Deleted, $recipients);
        }

        if ($archivable) {
            $filters->enable('archivable');
        }

        return $deleted;
    }
}
