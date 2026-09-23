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

namespace Augias\InvoiceBundle\Repository;

use Augias\InvoiceBundle\Entity\DisbursementReceipt;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;

/**
 * @extends EntityRepository<DisbursementReceipt>
 */
class DisbursementReceiptRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DisbursementReceipt::class);
    }
}
