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

namespace Augias\InvoiceBundle\Listener\Doctrine;

use Augias\CoreBundle\Storage\DocumentStorage;
use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Removes the file once its row is gone — after the flush, so a rolled-back
 * removal does not leave a row pointing at nothing.
 */
#[AsEntityListener(event: Events::postRemove, entity: DisbursementReceipt::class)]
final readonly class DisbursementReceiptFileListener
{
    public function __construct(
        private DocumentStorage $storage,
    ) {
    }

    public function postRemove(DisbursementReceipt $receipt): void
    {
        $this->storage->remove($receipt->getStoragePath());
    }
}
