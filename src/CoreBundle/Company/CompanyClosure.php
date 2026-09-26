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
use Symfony\Component\Uid\Ulid;
use function array_diff;
use function array_values;
use function in_array;
use function sprintf;

/**
 * Closing a company: scheduled, called off, and — once the date has passed —
 * carried out.
 *
 * The owner asks; the company then stays for GRACE_DAYS, read-only, so its
 * data can be taken out and a mistake undone. After that it is deleted with
 * everything in it, issued invoices included. Those are otherwise kept
 * whatever happens (see IssuedDocumentRetentionListener); the one exception
 * is this, which is why the guard asks isPurging().
 *
 * @see \Augias\CoreBundle\Tests\Company\CompanyClosureTest
 */
final class CompanyClosure
{
    public const int GRACE_DAYS = 30;

    /** @var list<string> the companies being deleted right now, by id */
    private array $purging = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyRepository $companies,
        private readonly ClockInterface $clock,
    ) {
    }

    public function schedule(Company $company): DateTimeImmutable
    {
        $closesAt = DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('+%d days', self::GRACE_DAYS));
        $company->scheduleClosure($closesAt);
        $this->entityManager->flush();

        return $closesAt;
    }

    public function cancel(Company $company): void
    {
        $company->cancelClosure();
        $this->entityManager->flush();
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
            $id = $company->getId()->toBase32();
            $this->purging[] = $id;

            try {
                $this->companies->deleteCompany($company->getId());
                $deleted[] = (string) $company->getName();
            } finally {
                $this->purging = array_values(array_diff($this->purging, [$id]));
            }
        }

        if ($archivable) {
            $filters->enable('archivable');
        }

        return $deleted;
    }

    public function isPurging(Company | Ulid | null $company): bool
    {
        if (null === $company) {
            return false;
        }

        $id = $company instanceof Company ? $company->getId() : $company;

        return in_array($id->toBase32(), $this->purging, true);
    }
}
