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

namespace Augias\ElectronicInvoicingBundle\Entity;

use Augias\CoreBundle\Export\Attribute\ExportIgnore;
use Augias\CoreBundle\Traits\Entity\CompanyAware;
use Augias\ElectronicInvoicingBundle\Repository\SuperPdpAuthorizationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * A company's SUPER PDP account, connected to the deployment's OAuth
 * application: the tokens that let Augias act for it, sealed by
 * {@see \Augias\ElectronicInvoicingBundle\Provider\SuperPdp\TokenCipher}.
 *
 * The provider setting points here by id, in its settings. Once written,
 * the tokens are only read and replaced through the repository's SQL, never
 * through this object: a refresh token works once, and two processes holding
 * the same stale copy would spend it twice.
 *
 * @see \Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpAccessTokens
 */
#[ORM\Entity(repositoryClass: SuperPdpAuthorizationRepository::class)]
#[ORM\Table(name: SuperPdpAuthorization::TABLE_NAME)]
class SuperPdpAuthorization
{
    public const string TABLE_NAME = 'einvoicing_super_pdp_authorization';

    use CompanyAware;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    /**
     * Null once SUPER PDP has refused it: the company withdrew its consent,
     * or nothing used it for a year. Only connecting again helps then.
     */
    #[ORM\Column(name: 'refresh_token', type: Types::TEXT, nullable: true)]
    #[ExportIgnore]
    private ?string $refreshToken;

    #[ORM\Column(name: 'access_token', type: Types::TEXT, nullable: true)]
    #[ExportIgnore]
    private ?string $accessToken;

    #[ORM\Column(name: 'access_token_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $accessTokenExpiresAt;

    /**
     * Held by the process refreshing the tokens until then, so that no other
     * spends the same refresh token meanwhile.
     */
    #[ORM\Column(name: 'refreshing_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $refreshingUntil = null;

    #[ORM\Column(name: 'connected_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $connectedAt;

    #[ORM\Column(name: 'disconnected_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $disconnectedAt = null;

    /**
     * @param string $refreshToken already sealed
     * @param string $accessToken  already sealed
     */
    public function __construct(string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt, DateTimeImmutable $connectedAt)
    {
        $this->refreshToken = $refreshToken;
        $this->accessToken = $accessToken;
        $this->accessTokenExpiresAt = SuperPdpAuthorizationRepository::local($accessTokenExpiresAt);
        $this->connectedAt = SuperPdpAuthorizationRepository::local($connectedAt);
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    /**
     * Sealed. For revoking it on disconnection; for anything else, go through
     * the repository.
     */
    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getConnectedAt(): DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function getDisconnectedAt(): ?DateTimeImmutable
    {
        return $this->disconnectedAt;
    }
}
