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

namespace Augias\CoreBundle\Entity;

use Augias\CoreBundle\Enum\SupplyType;
use Augias\TaxBundle\Entity\LineTax;
use Brick\Math\BigNumber;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Ulid;

interface LineInterface
{
    public function getId(): Ulid;

    public function setDescription(?string $description): self;

    public function getDescription(): ?string;

    public function setPrice(BigNumber | int | string $price): self;

    public function getPrice(): BigNumber;

    public function setQty(BigNumber | int | string $qty): self;

    public function getQty(): BigNumber;

    public function setTotal(BigNumber | int | string $total): self;

    public function getTotal(): BigNumber;

    public function addTax(LineTax $lineTax): static;

    public function removeTax(LineTax $lineTax): static;

    /**
     * @return Collection<int, LineTax>
     */
    public function getTaxes(): Collection;

    /**
     * Whether this line re-bills money advanced in the client's name.
     *
     * A disbursement — un débours — is not the company's turnover: the money
     * was spent on the client's behalf, against a supplier document made out
     * to the client, and is handed back to the euro. It therefore carries no
     * tax of its own, takes no part in the base a document-level rate or a
     * percentage discount applies to, and does not belong in the revenue book.
     *
     * Every line answers this so that the calculators do not have to ask what
     * kind of document they are looking at. Only invoice lines can actually be
     * marked; see {@see \Augias\InvoiceBundle\Entity\Line::isDisbursement()}.
     */
    public function isDisbursement(): bool;

    /**
     * Whether the line sells goods or a service, which decides when its VAT
     * falls due. See {@see SupplyType}.
     */
    public function getSupplyType(): SupplyType;
}
