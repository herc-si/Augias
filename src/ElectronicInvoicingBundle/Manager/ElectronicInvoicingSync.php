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

namespace Augias\ElectronicInvoicingBundle\Manager;

use Augias\CoreBundle\Entity\Company;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use function count;

/**
 * What the hourly tasks do, done now for one company: the invoices received
 * since, where the sent ones stand, and the e-reporting still to file. "Synchroniser maintenant" — the
 * person who just sent an invoice or was told one is waiting should not have
 * to wait for the hour.
 *
 * Once a minute at most: each run asks the platform about every invoice
 * still in progress.
 */
final readonly class ElectronicInvoicingSync
{
    private const int EVERY = 60;

    public function __construct(
        private ElectronicInvoiceReceiptManagerInterface $receipts,
        private SuperPdpStatusRefresher $statuses,
        private CacheItemPoolInterface $cache,
        private ElectronicReportManager $reports,
        private ElectronicDeclarationSync $declarations,
    ) {
    }

    /**
     * Null when the company synchronised less than a minute ago.
     *
     * @return array{received: int, refreshed: int, reported: int, failed: int}|null
     */
    public function syncNow(Company $company): ?array
    {
        $item = $this->cache->getItem('einvoicing_sync_' . $company->getId()->toBase58());

        if ($item->isHit()) {
            return null;
        }

        $this->cache->save($item->set(true)->expiresAfter(self::EVERY));

        $received = $this->receipts->importNew($company);
        $statuses = $this->statuses->refreshPending($company);
        $reported = $this->reports->reportPending($company);
        $declarationsFailed = 0;

        try {
            $this->declarations->refresh($company);
        } catch (RuntimeException) {
            $declarationsFailed = 1;
        }

        return [
            'received' => count($received),
            'refreshed' => $statuses['updated'],
            'reported' => $reported['reported'],
            'failed' => count($statuses['errors']) + $reported['failed'] + $declarationsFailed,
        ];
    }
}
