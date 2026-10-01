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

namespace Augias\ElectronicInvoicingBundle\Repository;

use Augias\ElectronicInvoicingBundle\Entity\SuperPdpAuthorization;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SolidWorx\Platform\PlatformBundle\Repository\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function date_default_timezone_get;
use function is_string;
use function sprintf;

/**
 * The tokens are read and written here in plain SQL, past Doctrine's
 * identity map: what another process wrote a moment ago has to be what this
 * one reads, or it spends a refresh token that no longer works.
 *
 * @extends EntityRepository<SuperPdpAuthorization>
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Repository\SuperPdpAuthorizationRepositoryTest
 */
final class SuperPdpAuthorizationRepository extends EntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuperPdpAuthorization::class);
    }

    /**
     * The sealed tokens as they stand in the database. Null when there is no
     * such authorization.
     *
     * @return array{refreshToken: ?string, accessToken: ?string, expiresAt: ?DateTimeImmutable}|null
     */
    public function readTokens(Ulid $id): ?array
    {
        $connection = $this->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative(
            sprintf('SELECT refresh_token, access_token, access_token_expires_at FROM %s WHERE id = :id', SuperPdpAuthorization::TABLE_NAME),
            ['id' => $id],
            ['id' => UlidType::NAME],
        );

        if (false === $row) {
            return null;
        }

        $expiresAt = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['access_token_expires_at'], $connection->getDatabasePlatform());

        return [
            'refreshToken' => is_string($row['refresh_token']) ? $row['refresh_token'] : null,
            'accessToken' => is_string($row['access_token']) ? $row['access_token'] : null,
            'expiresAt' => $expiresAt instanceof DateTimeImmutable ? $expiresAt : null,
        ];
    }

    /**
     * Takes the right to refresh the tokens until $until, unless another
     * process holds it. A holder that died lets go when its time is up.
     */
    public function claimRefresh(Ulid $id, DateTimeImmutable $now, DateTimeImmutable $until): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE %s SET refreshing_until = :until WHERE id = :id AND refresh_token IS NOT NULL AND (refreshing_until IS NULL OR refreshing_until < :now)', SuperPdpAuthorization::TABLE_NAME),
            ['until' => self::local($until), 'id' => $id, 'now' => self::local($now)],
            ['until' => Types::DATETIME_IMMUTABLE, 'id' => UlidType::NAME, 'now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The new pair, sealed, in place of the spent one; and lets go of the
     * claim.
     */
    public function storeTokens(Ulid $id, string $refreshToken, string $accessToken, DateTimeImmutable $expiresAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE %s SET refresh_token = :refresh, access_token = :access, access_token_expires_at = :expires, refreshing_until = NULL WHERE id = :id', SuperPdpAuthorization::TABLE_NAME),
            ['refresh' => $refreshToken, 'access' => $accessToken, 'expires' => self::local($expiresAt), 'id' => $id],
            ['expires' => Types::DATETIME_IMMUTABLE, 'id' => UlidType::NAME],
        );
    }

    /**
     * Lets go of the claim without new tokens: SUPER PDP could not be
     * reached, and the refresh token was not spent.
     */
    public function releaseRefresh(Ulid $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE %s SET refreshing_until = NULL WHERE id = :id', SuperPdpAuthorization::TABLE_NAME),
            ['id' => $id],
            ['id' => UlidType::NAME],
        );
    }

    /**
     * SUPER PDP refused the refresh token: the tokens are dropped, and the
     * company has to connect again.
     */
    public function markDisconnected(Ulid $id, DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE %s SET refresh_token = NULL, access_token = NULL, access_token_expires_at = NULL, refreshing_until = NULL, disconnected_at = :now WHERE id = :id', SuperPdpAuthorization::TABLE_NAME),
            ['now' => self::local($now), 'id' => $id],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => UlidType::NAME],
        );
    }

    /**
     * In the application's time zone: the columns keep no zone, and are read
     * back in that one — and compared, as text, with what is written.
     */
    public static function local(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
