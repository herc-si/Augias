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

namespace DoctrineMigrations;

use Augias\ClientBundle\Entity\Client;
use Augias\TaxBundle\Entity\TaxIdentifier;
use Augias\TaxBundle\Form\Type\TaxIdentifierType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use function sprintf;

/**
 * Whether a client is a private individual is now the box ticked on the
 * client, and nothing else: e-reporting and electronic invoicing both used to
 * guess it from the identifiers, and the box was never really chosen — it
 * defaults to a business. A client without a SIRET, a SIREN or a VAT number
 * is what e-reporting already treated as a private individual: the box now
 * says so, and nothing changes in what is reported.
 */
final class Version40000_57 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients with no business identifier are private individuals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            sprintf(
                'UPDATE %1$s SET is_company = ? WHERE is_company = ? AND NOT EXISTS (SELECT 1 FROM %2$s t WHERE t.client_id = %1$s.id AND t.label IN (?) AND t.value IS NOT NULL AND t.value <> \'\')',
                Client::TABLE_NAME,
                TaxIdentifier::TABLE_NAME,
            ),
            [false, true, TaxIdentifierType::PROMINENT_LABELS],
            [Types::BOOLEAN, Types::BOOLEAN, ArrayParameterType::STRING],
        );
    }

    public function down(Schema $schema): void
    {
        // Which clients were businesses before is not kept: the box was a
        // default nobody chose, so there is nothing to restore.
    }
}
