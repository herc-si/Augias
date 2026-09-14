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

namespace Augias\InvoiceBundle\DTO;

use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\AllocationKind;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

final class CreditNoteAllocationDTO
{
    #[Assert\NotNull]
    public ?AllocationKind $kind = AllocationKind::Offset;

    /**
     * In minor units, like every other amount. The form scales what was typed.
     */
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?string $amount = null;

    /**
     * Required for an offset, forbidden for a refund — enforced by the
     * allocator rather than here, so the rule holds for callers that never go
     * near a form.
     */
    public ?Invoice $invoice = null;

    #[Assert\NotNull]
    public ?DateTimeImmutable $allocatedOn = null;

    public ?string $notes = null;
}
