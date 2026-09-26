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

use Augias\CoreBundle\Doctrine\Type\BigIntegerType;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute as Serialize;

#[ORM\Embeddable]
class Discount
{
    final public const string TYPE_PERCENTAGE = 'percentage';

    final public const string TYPE_MONEY = 'money';

    #[ORM\Column(name: 'valueMoney_amount', type: BigIntegerType::NAME)]
    #[Serialize\Groups(['invoice_api', 'quote_api', 'client_api'])]
    private BigNumber $valueMoney;

    #[ORM\Column(name: 'value_percentage', type: Types::FLOAT, nullable: true)]
    #[Serialize\Groups(['invoice_api', 'quote_api', 'client_api'])]
    private ?float $valuePercentage = null;

    #[ORM\Column(name: 'type', type: Types::STRING, nullable: true)]
    #[Serialize\Groups(['invoice_api', 'quote_api', 'client_api'])]
    private ?string $type = self::TYPE_PERCENTAGE;

    public function __construct()
    {
        $this->valueMoney = BigInteger::zero();
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getValueMoney(): BigNumber
    {
        return $this->valueMoney;
    }

    /**
     * @throws MathException
     */
    public function setValueMoney(BigNumber | float | int | string $valueMoney): self
    {
        $this->valueMoney = BigNumber::of(is_float($valueMoney) ? (string) $valueMoney : $valueMoney);

        return $this;
    }

    public function getValuePercentage(): ?float
    {
        return $this->valuePercentage;
    }

    public function setValuePercentage(float $valuePercentage): self
    {
        $this->valuePercentage = $valuePercentage;

        return $this;
    }

    public function getValue(): float | BigNumber
    {
        // Mirrors setValue(): an unset type reads as a percentage rather than
        // as no discount at all.
        return self::TYPE_MONEY === $this->getType()
            ? $this->getValueMoney()
            : $this->getValuePercentage() ?? 0.0;
    }

    /**
     * @throws MathException
     */
    public function setValue(BigNumber | float | int | string $value): self
    {
        // Anything that is not an explicit money discount is treated as a
        // percentage, which is this class's own default type. The switch used
        // to have no default branch, so an unset or unrecognised type silently
        // discarded the amount and the discount read as zero — a failure with
        // no error anywhere to explain it.
        if (self::TYPE_MONEY === $this->getType()) {
            $this->setValuePercentage(0.0);
            $this->setValueMoney(BigNumber::of(is_float($value) ? (string) $value : $value));

            return $this;
        }

        $this->setValuePercentage(BigNumber::of(is_float($value) ? (string) $value : $value)->toBigDecimal()->toFloat());
        $this->setValueMoney(BigDecimal::zero());

        return $this;
    }

    /**
     * What the discount takes off a net amount, in the same minor units,
     * rounded to the cent.
     *
     * The net is the tax-exclusive fees: a discount on the invoice lowers
     * the price, and so the base VAT is charged on (CGI art. 267-II-1°). It
     * never goes below nothing, nor above the net — a discount larger than
     * the sale does not make the client owed money.
     *
     * @throws MathException
     */
    public function amountOn(BigNumber $net): BigDecimal
    {
        $net = $net->toBigDecimal();

        if (! $net->isPositive()) {
            return BigDecimal::zero();
        }

        $amount = self::TYPE_MONEY === $this->getType()
            ? $this->getValueMoney()->toBigDecimal()
            : $net->multipliedBy(BigDecimal::of((string) ($this->getValuePercentage() ?? 0.0)))->dividedBy(100, 10, RoundingMode::HalfEven);

        $amount = $amount->toScale(0, RoundingMode::HalfUp);

        if ($amount->isNegative()) {
            return BigDecimal::zero();
        }

        return $amount->isGreaterThan($net) ? $net : $amount;
    }
}
