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

namespace Augias\CoreBundle\Tests\Functional;

use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\OperatorAccess;
use Augias\CoreBundle\Enum\AccessReason;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Ulid;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * The page a customer opens to find out who looked at their file.
 *
 * What it has to hold: the answer is about their own company and nobody
 * else's, it is readable as words rather than as machine values, and a reason
 * written by operator tooling newer than this build still shows up instead of
 * disappearing.
 */
#[Group('functional')]
final class AccessLogTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testACompanySeesItsOwnAccessAndNotAnotherCompanys(): void
    {
        $alpha = $this->seedAccount('alpha');
        $beta = $this->seedAccount('beta');

        $this->record($alpha['company'], 'someone@host.example', AccessReason::TenantList);
        $this->record($beta['company'], 'elsewhere@host.example', AccessReason::UserList);

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($alpha['user'])
            ->visit('/access-log')
            ->assertSuccessful()
            ->assertSee('someone@host.example')
            ->assertNotSee('elsewhere@host.example');
    }

    /**
     * The reason is shown translated, not as the value stored in the row.
     */
    public function testTheReasonIsShownInWords(): void
    {
        $alpha = $this->seedAccount('alpha');

        $this->record($alpha['company'], 'someone@host.example', AccessReason::TenantList);

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($alpha['user'])
            ->visit('/access-log')
            ->assertSee('Company list')
            ->assertNotSee('tenant_list');
    }

    /**
     * Operator tooling can be a version ahead of the application it runs
     * beside. A reason this build has never heard of is shown as it was
     * written — the row is an account of access, and dropping the lines it
     * cannot label would make it a worse one.
     */
    public function testAReasonThisBuildDoesNotKnowIsStillShown(): void
    {
        $alpha = $this->seedAccount('alpha');

        // Written straight to the table: the entity only accepts reasons this
        // build knows, which is exactly the situation being stood up here.
        $this->em->getConnection()->insert(
            OperatorAccess::TABLE_NAME,
            [
                'id' => new Ulid(),
                'company_id' => $alpha['company']->getId(),
                'operator' => 'newer@host.example',
                'reason' => 'a_reason_from_the_future',
                'accessed_at' => new DateTimeImmutable(),
            ],
            [
                'id' => UlidType::NAME,
                'company_id' => UlidType::NAME,
                'operator' => Types::STRING,
                'reason' => Types::STRING,
                'accessed_at' => Types::DATETIME_IMMUTABLE,
            ],
        );

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($alpha['user'])
            ->visit('/access-log')
            ->assertSee('newer@host.example')
            ->assertSee('a_reason_from_the_future');
    }

    /**
     * Nothing in this repository writes to the log, so an install that has
     * never been read still has to have an answer, and it is "nobody".
     */
    public function testAnEmptyLogSaysSo(): void
    {
        $alpha = $this->seedAccount('alpha');

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($alpha['user'])
            ->visit('/access-log')
            ->assertSuccessful()
            ->assertSee('No access recorded');
    }

    private function record(Company $company, string $operator, AccessReason $reason): void
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        $this->em->persist(new OperatorAccess($company, $operator, $reason, new DateTimeImmutable()));
        $this->em->flush();

        if ($wasEnabled) {
            $filters->enable('company');
        }
    }

    /**
     * @return array{company: Company, user: User}
     */
    private function seedAccount(string $slug): array
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        $company = CompanyFactory::createOne(['name' => $slug . ' Inc']);

        $user = UserFactory::createOne([
            'email' => $slug . '@access-log.test',
            'companies' => [$company],
        ]);

        if ($wasEnabled) {
            $filters->enable('company');
        }

        return [
            'company' => $company,
            'user' => $user,
        ];
    }
}
