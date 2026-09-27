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

namespace Augias\CatalogBundle\Entity;

use Augias\CatalogBundle\Enum\ProductType;
use Augias\CatalogBundle\Repository\ProductRepository;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A re-usable catalogue entry that pre-fills an invoice or quote line, so the
 * same service never has to be typed out twice.
 *
 * Prices are held in the company's own currency and copied onto the line as-is;
 * a client billed in another currency needs the amount adjusting by hand, which
 * is why nothing here is snapshot onto the document beyond what the line stores
 * for itself.
 *
 * @see \Augias\CatalogBundle\Tests\Entity\ProductTest
 */
#[ORM\Table(name: Product::TABLE_NAME)]
#[ORM\UniqueConstraint(columns: ['reference', 'company_id'])]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product implements Stringable
{
    final public const string TABLE_NAME = 'products';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 125)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 125)]
    private ?string $name = null;

    /**
     * Copied into the line's own description, which the user is then free to
     * edit per document without touching the catalogue.
     */
    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Optional internal reference (SKU). Unique per company when set, so an
     * import or a quick search can address an entry unambiguously.
     */
    #[ORM\Column(name: 'reference', type: Types::STRING, length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $reference = null;

    #[ORM\Column(name: 'type', type: Types::STRING, length: 16, enumType: ProductType::class)]
    private ProductType $type = ProductType::Service;

    #[ORM\Column(name: 'unit', type: Types::STRING, length: 16, enumType: QuantityUnit::class)]
    private QuantityUnit $unit = QuantityUnit::Unit;

    /**
     * Minor units, and typed BigNumber rather than BigInteger on purpose: the
     * money form field hands back a BigDecimal (see MoneyBundle's
     * ViewTransformer), and BigIntegerType scales whatever BigNumber it is
     * given down to an integer on the way to the database. Narrowing this to
     * BigInteger made every save of this form fail type validation before it
     * ever reached Doctrine. Bill::$totalAmount has the same shape.
     */
    #[ORM\Column(name: 'sale_price', type: BigIntegerType::NAME)]
    #[Assert\NotNull]
    private ?BigNumber $salePrice = null;

    /**
     * What the entry costs this company, used to show a margin. Null when it
     * isn't bought in — a service billed at a day rate usually has none.
     */
    #[ORM\Column(name: 'purchase_price', type: BigIntegerType::NAME, nullable: true)]
    private ?BigNumber $purchasePrice = null;

    #[ORM\ManyToOne(targetEntity: Tax::class)]
    #[ORM\JoinColumn(name: 'tax_id', nullable: true, onDelete: 'SET NULL')]
    private ?Tax $tax = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /**
     * Retired entries stay for the documents that already reference them but
     * drop out of the picker. Deleting instead would lose that history.
     */
    #[ORM\Column(name: 'active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getType(): ProductType
    {
        return $this->type;
    }

    public function setType(ProductType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getUnit(): QuantityUnit
    {
        return $this->unit;
    }

    public function setUnit(QuantityUnit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getSalePrice(): ?BigNumber
    {
        return $this->salePrice;
    }

    public function setSalePrice(?BigNumber $salePrice): self
    {
        $this->salePrice = $salePrice;

        return $this;
    }

    public function getPurchasePrice(): ?BigNumber
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?BigNumber $purchasePrice): self
    {
        $this->purchasePrice = $purchasePrice;

        return $this;
    }

    public function getTax(): ?Tax
    {
        return $this->tax;
    }

    public function setTax(?Tax $tax): self
    {
        $this->tax = $tax;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Sale minus purchase price, or null when there is nothing to compare.
     */
    public function getMargin(): ?BigNumber
    {
        if (! $this->salePrice instanceof BigNumber || ! $this->purchasePrice instanceof BigNumber) {
            return null;
        }

        // Through BigDecimal because the two sides may be a BigInteger read
        // back from the database and a BigDecimal straight off the form, and
        // BigInteger::minus() rejects a decimal operand.
        return $this->salePrice->toBigDecimal()->minus($this->purchasePrice->toBigDecimal());
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
