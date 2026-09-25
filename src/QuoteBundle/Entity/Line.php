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

namespace Augias\QuoteBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Augias\ApiBundle\Serializer\Normalizer\BigIntegerNormalizer;
use Augias\ApiBundle\State\Processor\QuoteLinePersistProcessor;
use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Augias\CoreBundle\Doctrine\Type\QuantityType;
use Augias\CoreBundle\Entity\LineInterface;
use Augias\CoreBundle\Enum\SupplyType;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Augias\QuoteBundle\Repository\LineRepository;
use Augias\TaxBundle\Entity\LineTax;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: Line::TABLE_NAME)]
#[ORM\Entity(repositoryClass: LineRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'QuoteLine',
    operations: [
        new GetCollection(
            uriTemplate: '/quotes/{quoteId}/lines',
            uriVariables: [
                'quoteId' => new Link(
                    fromProperty: 'lines',
                    fromClass: Quote::class,
                ),
            ],
        ),
        new Post(
            uriTemplate: '/quotes/{quoteId}/lines',
            uriVariables: [
                'quoteId' => new Link(
                    fromProperty: 'lines',
                    fromClass: Quote::class,
                ),
            ],
            processor: QuoteLinePersistProcessor::class,
        ),
        new Get(
            uriTemplate: '/quotes/{quoteId}/line/{id}',
            uriVariables: [
                'quoteId' => new Link(
                    fromProperty: 'lines',
                    fromClass: Quote::class,
                ),
                'id' => new Link(
                    fromClass: Line::class,
                ),
            ],
        ),
        new Patch(
            uriTemplate: '/quotes/{quoteId}/line/{id}',
            uriVariables: [
                'quoteId' => new Link(
                    fromProperty: 'lines',
                    fromClass: Quote::class,
                ),
                'id' => new Link(
                    fromClass: Line::class,
                ),
            ],
        ),
        new Delete(
            uriTemplate: '/quotes/{quoteId}/line/{id}',
            uriVariables: [
                'quoteId' => new Link(
                    fromProperty: 'lines',
                    fromClass: Quote::class,
                ),
                'id' => new Link(
                    fromClass: Line::class,
                ),
            ],
        ),
    ],
    normalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ]
)]
#[ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'company', inversedBy: 'quoteLines')])]
class Line implements LineInterface, Stringable
{
    final public const string TABLE_NAME = 'quote_lines';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[Groups(['quote_api:read'])]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'description', type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Groups(['quote_api:read', 'quote_api:write'])]
    private ?string $description = null;

    #[ORM\Column(name: 'price_amount', type: BigIntegerType::NAME)]
    #[Assert\NotBlank]
    #[Groups(['quote_api', 'client_api', 'create_quote_api', 'quote_api:read', 'quote_api:write'])]
    #[ApiProperty(
        openapiContext: [
            'type' => 'number',
        ],
        jsonSchemaContext: [
            'type' => 'number',
        ]
    )]
    private BigNumber $price;

    #[ORM\Column(name: 'qty', type: QuantityType::NAME, precision: QuantityType::PRECISION, scale: QuantityType::SCALE)]
    #[Assert\NotBlank]
    #[Groups(['quote_api:read', 'quote_api:write'])]
    #[Context(
        normalizationContext: [BigIntegerNormalizer::MONETARY => false],
        denormalizationContext: [BigIntegerNormalizer::MONETARY => false],
    )]
    #[ApiProperty(
        openapiContext: [
            'type' => 'number',
        ],
        jsonSchemaContext: [
            'type' => 'number',
        ]
    )]
    private BigNumber $qty;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[ApiProperty(
        writable: false,
        writableLink: false,
        example: '/api/quotes/3fa85f64-5717-4562-b3fc-2c963f66afa6'
    )]
    private ?Quote $quote = null;

    /**
     * @var Collection<int, LineTax>
     */
    #[ORM\OneToMany(targetEntity: LineTax::class, mappedBy: 'quoteLine', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['quote_api:read', 'quote_api:write'])]
    private Collection $taxes;

    #[ORM\Column(name: 'total_amount', type: BigIntegerType::NAME)]
    #[Groups(['quote_api:read'])]
    #[ApiProperty(
        openapiContext: [
            'type' => 'number',
        ],
        jsonSchemaContext: [
            'type' => 'number',
        ]
    )]
    private BigNumber $total;

    /**
     * Goods or a service, carried onto the invoice the quote becomes — where
     * it decides when the VAT falls due. See {@see SupplyType}.
     */
    #[ORM\Column(name: 'supply_type', type: Types::STRING, length: 10, enumType: SupplyType::class, options: ['default' => 'services'])]
    #[Groups(['quote_api:read', 'quote_api:write'])]
    private SupplyType $supplyType = SupplyType::Services;

    public function __construct()
    {
        $this->total = BigDecimal::zero();
        $this->price = BigDecimal::zero();
        $this->qty = BigDecimal::one();
        $this->taxes = new ArrayCollection();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @throws MathException
     */
    public function setPrice(BigNumber | int | string $price): static
    {
        $this->price = BigNumber::of($price);

        return $this;
    }

    public function getPrice(): BigNumber
    {
        return $this->price;
    }

    /**
     * @throws MathException
     */
    public function setQty(BigNumber | int | string $qty): static
    {
        // Normalized on the way in, not on the way out: updateTotal() runs before DBAL
        // converts the quantity, so the total has to be computed from the same value the
        // column will hold. See QuantityType::normalize().
        $this->qty = QuantityType::normalize(BigNumber::of($qty));

        return $this;
    }

    public function getQty(): BigNumber
    {
        return $this->qty;
    }

    public function setQuote(?Quote $quote = null): static
    {
        $this->quote = $quote;

        return $this;
    }

    public function getQuote(): Quote
    {
        return $this->quote;
    }

    /**
     * @throws MathException
     */
    public function setTotal(BigNumber | int | string $total): static
    {
        $this->total = BigNumber::of($total);

        return $this;
    }

    public function getTotal(): BigNumber
    {
        return $this->total;
    }

    /**
     * Never, on a quote.
     *
     * A disbursement is a fact about money that has already been advanced,
     * proved by a supplier's document made out to the client. A quote is a
     * proposal, and nothing has been advanced yet — the line becomes a
     * disbursement on the invoice that follows, where the proof exists.
     */
    public function isDisbursement(): bool
    {
        return false;
    }

    public function getSupplyType(): SupplyType
    {
        return $this->supplyType;
    }

    public function setSupplyType(SupplyType $supplyType): static
    {
        $this->supplyType = $supplyType;

        return $this;
    }

    /**
     * @return Collection<int, LineTax>
     */
    public function getTaxes(): Collection
    {
        return $this->taxes;
    }

    public function addTax(LineTax $lineTax): static
    {
        if (! $this->taxes->contains($lineTax)) {
            $this->taxes->add($lineTax);
            $lineTax->setQuoteLine($this);
        }

        return $this;
    }

    public function removeTax(LineTax $lineTax): static
    {
        if ($this->taxes->removeElement($lineTax) && $lineTax->getQuoteLine() === $this) {
            $lineTax->setQuoteLine(null);
        }

        return $this;
    }

    /**
     * @throws MathException
     * @throws RoundingNecessaryException
     */
    #[ORM\PrePersist]
    public function updateTotal(): static
    {
        $this->total = $this->getPrice()
            ->toBigDecimal()
            ->multipliedBy($this->qty->toBigDecimal());

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->description;
    }
}
