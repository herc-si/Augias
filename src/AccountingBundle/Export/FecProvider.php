<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\AccountingBundle\Export;

use Augias\AccountingBundle\Fec\FecGenerator;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Export\Attachment\ExportAttachment;
use Augias\CoreBundle\Export\Attachment\ExportAttachmentProvider;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Ulid;

/**
 * One FEC per financial year, with its notice, in the company's export: the
 * books in the form the tax administration asks for them — what an owner
 * needs to keep once the company is closed.
 */
final readonly class FecProvider implements ExportAttachmentProvider
{
    public function __construct(
        private FecGenerator $generator,
        private CompanySelector $companySelector,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function attachments(): Generator
    {
        $companyId = $this->companySelector->getCompany();
        $company = $companyId instanceof Ulid ? $this->entityManager->find(Company::class, $companyId) : null;

        if (! $company instanceof Company) {
            return;
        }

        foreach ($this->generator->years($company, \DateTimeImmutable::createFromInterface($this->clock->now())) as $year) {
            $fec = $this->generator->generate($company, $year);

            if (0 === $fec->entries) {
                continue;
            }

            yield new ExportAttachment('fec/' . $fec->filename, static fn (): string => $fec->content);
            yield new ExportAttachment('fec/Notice_' . $fec->filename, static fn (): string => $fec->notice);
        }
    }
}
