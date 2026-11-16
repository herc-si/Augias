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
use Augias\CoreBundle\Entity\ExportJob;
use Augias\CoreBundle\Export\Enum\ExportStatus;
use Augias\CoreBundle\Export\Message\RequestCompanyExport;
use Augias\CoreBundle\Repository\ExportJobRepository;
use Augias\UserBundle\Entity\Membership;
use Augias\UserBundle\Enum\CompanyPermission;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;
use function in_array;
use function is_file;

/**
 * The archive a closing company is handed: its full export — the invoices
 * and credit notes as PDF, the receipts, the accounting — made without
 * anyone having to ask, and sent to the people who run it.
 *
 * Keeping invoices is the company's obligation, not the service's, and the
 * closure deletes them. So the company does not go until this archive
 * exists; see CompanyClosure::purgeDue().
 *
 * @see \Augias\CoreBundle\Tests\Company\ClosureArchiveTest
 */
final readonly class ClosureArchive
{
    /** After this long without finishing, an export is taken for lost and made again. */
    private const string LOST_AFTER = '-1 day';

    public function __construct(
        private ExportJobRepository $jobs,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * Has the archive made, unless it exists or is on its way.
     *
     * @return bool whether an export was started
     */
    public function prepare(Company $company): bool
    {
        if (! $company->isClosing() || $this->ready($company) instanceof ExportJob || $this->isUnderway($company)) {
            return false;
        }

        $onBehalfOf = $this->someoneRunning($company);

        if (! $onBehalfOf instanceof Ulid) {
            return false;
        }

        $job = ExportJob::forClosure($onBehalfOf)->setCompany($company);
        $this->jobs->save($job);

        $this->bus->dispatch(new RequestCompanyExport($job->getId(), $company->getId(), $onBehalfOf));

        return true;
    }

    /**
     * The archive of the closure in force, once it can be downloaded.
     */
    public function ready(Company $company): ?ExportJob
    {
        foreach ($this->jobs->findClosureArchives($company, $company->getClosureScheduledAt()) as $job) {
            $path = $job->resolveAbsolutePath($this->projectDir);

            if (ExportStatus::Completed === $job->getStatus() && null !== $path && is_file($path)) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Whether nothing more is owed before the company goes: the archive is
     * there, or there is nobody left to hand it to.
     */
    public function isSettled(Company $company): bool
    {
        return $this->ready($company) instanceof ExportJob || ! $this->someoneRunning($company) instanceof Ulid;
    }

    /**
     * Writes down, before the company goes, that its archive was made and
     * whether it was taken — the rows that say so go with the company.
     */
    public function record(Company $company): void
    {
        $archive = $this->ready($company);

        $this->logger->notice('Company closed: its closure archive was {outcome}.', [
            'outcome' => match (true) {
                ! $archive instanceof ExportJob => 'not made, nobody ran the company',
                $archive->getDownloadedAt() instanceof DateTimeImmutable => 'downloaded',
                default => 'made available, never downloaded',
            },
            'company_id' => $company->getId()->toString(),
            'archive_completed_at' => $archive?->getCompletedAt()?->format(DATE_ATOM),
            'archive_downloaded_at' => $archive?->getDownloadedAt()?->format(DATE_ATOM),
        ]);
    }

    /**
     * Deletes the export files of a company that is gone: they hold what the
     * closure promised to delete.
     */
    public function removeFiles(Ulid $companyId): void
    {
        $this->filesystem->remove($this->projectDir . '/var/exports/' . $companyId->toBase58());
    }

    private function isUnderway(Company $company): bool
    {
        $lostBefore = DateTimeImmutable::createFromInterface($this->clock->now())->modify(self::LOST_AFTER);
        return array_any($this->jobs->findClosureArchives($company, $company->getClosureScheduledAt()), fn ($job) => in_array($job->getStatus(), [ExportStatus::Pending, ExportStatus::Processing], true) && $job->getCreatedAt() > $lostBefore);
    }

    /**
     * An export is filed under a person; the archive goes to all who run the
     * company, so any one of them will do.
     */
    private function someoneRunning(Company $company): ?Ulid
    {
        foreach ($company->getMemberships() as $membership) {
            if ($membership instanceof Membership && $membership->getRole()->can(CompanyPermission::ManageMembers)) {
                return $membership->getUser()->getId();
            }
        }

        return null;
    }
}
