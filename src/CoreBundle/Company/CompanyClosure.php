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
 * data can be taken out and a mistake undone — an archive of it is made and
 * sent to the people who run it (see ClosureArchive). After that it is deleted with
 * everything in it, issued invoices included. Those are otherwise kept
 * whatever happens (see IssuedDocumentRetentionListener); the one exception
 * is this, which the guard learns from CompanyPurgeContext.
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyClosureTest
 */
final readonly class CompanyClosure
{
    public const int GRACE_DAYS = 30;

    /** How long before the date the last reminder goes out. */
    public const int REMINDER_DAYS = 7;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyRepository $companies,
        private ClockInterface $clock,
        private ?CompanyClosureNotifier $notifier = null,
        private CompanyPurgeContext $context = new CompanyPurgeContext(),
        private ?ClosureArchive $archive = null,
    ) {
    }

    public function schedule(Company $company): DateTimeImmutable
    {
        $closesAt = DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('+%d days', self::GRACE_DAYS));
        $this->scheduleAt($company, $closesAt, ClosureReason::Requested);

        return $closesAt;
    }

    /**
     * Sets the date and tells the people who run the company, in the words
     * that fit the reason.
     */
    public function scheduleAt(Company $company, DateTimeImmutable $closesAt, ClosureReason $reason): void
    {
        $company->scheduleClosure($closesAt, $reason);
        $this->entityManager->flush();

        $this->notifier?->notify($company, ClosureNotice::Scheduled);
        $this->archive?->prepare($company);
    }

    /**
     * Has the archive made for every closing company still without one —
     * closures scheduled before archives existed, exports that failed.
     *
     * @return list<string> the companies whose date has passed and that stay until their archive is there
     */
    public function prepareArchives(): array
    {
        if (! $this->archive instanceof ClosureArchive) {
            return [];
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $waiting = [];

        foreach ($this->companies->findClosing() as $company) {
            if ($this->archive->isSettled($company)) {
                continue;
            }

            $this->archive->prepare($company);

            if ($company->getClosesAt() <= $now) {
                $waiting[] = (string) $company->getName();
            }
        }

        return $waiting;
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

            $this->notifier?->notify($company, ClosureNotice::Reminder, archive: $this->archive?->ready($company));
            $company->markClosureReminded($now);
            ++$reminded;
        }

        $this->entityManager->flush();

        return $reminded;
    }

    /**
     * Deletes every company whose closure date has passed — once its archive
     * exists. The invoices go with the company and nobody else keeps them:
     * one whose archive could not be made waits, and prepareArchives() says so.
     *
     * @return list<string> the names of the companies deleted
     */
    public function purgeDue(): array
    {
        $deleted = [];

        foreach ($this->companies->findClosingBefore(DateTimeImmutable::createFromInterface($this->clock->now())) as $company) {
            if (false === $this->archive?->isSettled($company)) {
                continue;
            }

            $this->purge($company);
            $deleted[] = (string) $company->getName();
        }

        return $deleted;
    }

    /**
     * Deletes the company and everything in it, then says so to those who ran it.
     */
    public function purge(Company $company): void
    {
        // Archived documents go with the company too: hidden by the filter,
        // they would escape the cascade and, on SQLite, stay behind.
        $filters = $this->entityManager->getFilters();
        $archivable = $filters->isEnabled('archivable');

        if ($archivable) {
            $filters->disable('archivable');
        }

        try {
            // Read before the memberships go with the company.
            $recipients = $this->notifier?->recipients($company) ?? [];
            $companyId = $company->getId();
            $this->archive?->record($company);

            $this->context->during($company, fn () => $this->companies->deleteCompany($companyId));
            $this->archive?->removeFiles($companyId);
        } finally {
            if ($archivable) {
                $filters->enable('archivable');
            }
        }

        $this->notifier?->notify($company, ClosureNotice::Deleted, $recipients);
    }
}
