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

namespace Augias\AccountingBundle\Entity;

use Augias\AccountingBundle\Repository\BankAccountRepository;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\CoreBundle\Traits\Entity\TimeStampable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;
use function str_replace;
use function strtoupper;
use function trim;

/**
 * A bank account whose statements are imported by hand — downloaded from the
 * bank as a file, not fetched from it: no aggregator, no approval, no cost.
 *
 * @see \Augias\AccountingBundle\Tests\Bank\StatementImporterTest
 */
#[ORM\Table(name: BankAccount::TABLE_NAME)]
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
class BankAccount
{
    final public const string TABLE_NAME = 'accounting_bank_accounts';

    use CompanyAware;
    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(name: 'iban', type: Types::STRING, length: 34, nullable: true)]
    #[Assert\Iban]
    private ?string $iban = null;

    /** The currency its statements are in: amounts are stored in its minor units. */
    #[ORM\Column(name: 'currency_code', type: Types::STRING, length: 3)]
    #[Assert\Currency]
    private string $currencyCode = 'EUR';

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): self
    {
        $this->iban = null === $iban || '' === trim($iban) ? null : strtoupper(str_replace(' ', '', $iban));

        return $this;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): self
    {
        $this->currencyCode = strtoupper($currencyCode);

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
