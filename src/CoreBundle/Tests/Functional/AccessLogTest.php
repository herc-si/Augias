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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Enum\ClientStatus;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Entity\RecordAccess;
use Augias\CoreBundle\Enum\RecordKind;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function array_map;

/**
 * Opening a record leaves a line, and the line is the person's own.
 */
#[Group('functional')]
final class AccessLogTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testOpeningAClientIsRecordedAndShownBack(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = UserFactory::createOne(['email' => 'alpha@journal.test', 'companies' => [$company]]);
        $client = ClientFactory::createOne([
            'company' => $company,
            'name' => 'Acme Ltd',
            'status' => ClientStatus::Active,
        ]);

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($user)
            ->visit('/clients/view/' . $client->getId())
            ->assertSuccessful()
            ->visit('/access-log')
            ->assertSuccessful()
            ->assertSee('Acme Ltd');

        self::assertSame([RecordKind::Client], $this->kindsFor($user));
    }

    /**
     * A reload is not a second visit. Without this the journal fills with the
     * same line, and the value of the page is that it can be read at a glance.
     */
    public function testOpeningTheSameRecordTwiceIsOneLine(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = UserFactory::createOne(['email' => 'alpha@journal.test', 'companies' => [$company]]);
        $client = $this->client($company, 'Acme Ltd');

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($user)
            ->visit('/clients/view/' . $client->getId())
            ->visit('/clients/view/' . $client->getId());

        self::assertCount(1, $this->kindsFor($user));
    }

    /**
     * The journal is a memory aid, not a way to watch a colleague: the page
     * takes the signed-in user, and a second person in the same company sees
     * their own empty trail rather than the first one's.
     */
    public function testAColleagueDoesNotSeeYourTrail(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $first = UserFactory::createOne(['email' => 'first@journal.test', 'companies' => [$company]]);
        $second = UserFactory::createOne(['email' => 'second@journal.test', 'companies' => [$company]]);
        $client = $this->client($company, 'Acme Ltd');

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($first)
            ->visit('/clients/view/' . $client->getId());

        $this->browser()
            ->throwExceptions()
            ->actingAs($second)
            ->visit('/access-log')
            ->assertSuccessful()
            ->assertNotSee('Acme Ltd');
    }

    /**
     * The quote form hangs a draft on each of the client's contacts. The
     * journal used to flush the whole unit of work to write its line, found
     * that draft, and turned the page into a 500 — every time the visit was
     * journalled.
     */
    public function testOpeningANewQuoteForAClientWithContactsIsRecorded(): void
    {
        $company = CompanyFactory::createOne(['name' => 'Alpha Inc']);
        $user = UserFactory::createOne(['email' => 'quote@journal.test', 'companies' => [$company]]);
        $client = $this->client($company, 'Acme Ltd');
        ContactFactory::createOne(['client' => $client, 'company' => $company]);

        $this->em->clear();

        $this->browser()
            ->throwExceptions()
            ->actingAs($user)
            ->visit('/quotes/create/' . $client->getId())
            ->assertSuccessful();

        self::assertSame([RecordKind::Client], $this->kindsFor($user));
    }

    private function client(Company $company, string $name): Client
    {
        return ClientFactory::createOne([
            'company' => $company,
            'name' => $name,
            'status' => ClientStatus::Active,
        ]);
    }

    /**
     * @return list<RecordKind>
     */
    private function kindsFor(User $user): array
    {
        $this->em->clear();

        return array_map(
            static fn (RecordAccess $record): RecordKind => $record->getKind(),
            $this->em->getRepository(RecordAccess::class)->findBy(['user' => $user->getId()]),
        );
    }
}
