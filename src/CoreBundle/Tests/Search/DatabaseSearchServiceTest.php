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

namespace Augias\CoreBundle\Tests\Search;

use const JSON_THROW_ON_ERROR;
use Augias\ClientBundle\Enum\ClientStatus;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Search\DatabaseSearchService;
use Augias\CoreBundle\Search\ParsedQuery;
use Augias\CoreBundle\Search\SearchResult;
use Augias\CoreBundle\Test\Factory\CompanyFactory;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\QuoteBundle\Test\Factory\QuoteFactory;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function array_keys;
use function array_map;
use function json_decode;

/**
 * The search bar answered from the database, for the installations without
 * Meilisearch: it finds what is typed, in the active company only.
 */
#[CoversClass(DatabaseSearchService::class)]
#[Group('functional')]
final class DatabaseSearchServiceTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testFindsClientsContactsAndDocumentsByWhatIsTyped(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont SARL', 'status' => ClientStatus::Active]);
        ContactFactory::createOne(['company' => $this->company, 'client' => $client, 'firstName' => 'Marie', 'lastName' => 'Dupontel', 'email' => 'marie@example.org']);
        InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending, 'invoiceId' => 'FACT-7-2026']);
        QuoteFactory::createOne(['company' => $this->company, 'client' => $client]);
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Boulangerie Petit', 'status' => ClientStatus::Active]);

        $results = $this->search('dupont');

        self::assertSame(['clients', 'contacts', 'invoices', 'quotes'], array_keys($results), 'Case does not matter; the client name finds its documents too.');
        self::assertSame(['Atelier Dupont SARL'], $this->titles($results['clients']));
        self::assertSame(['Marie Dupontel'], $this->titles($results['contacts']));
        self::assertSame(['FACT-7-2026'], $this->titles($results['invoices']));
        self::assertStringContainsString('/invoices/view/', $results['invoices'][0]->url);

        self::assertSame(['invoices'], array_keys($this->search('fact-7')), 'A document is found by its number.');
        self::assertSame([], $this->search('introuvable'));
    }

    public function testInRestrictsTheSearchToTheNamedLists(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont SARL', 'status' => ClientStatus::Active]);
        InvoiceFactory::createOne(['company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending]);

        $results = self::getContainer()->get(DatabaseSearchService::class)->search(new ParsedQuery('dupont', indices: ['invoices']));

        self::assertSame(['invoices'], array_keys($results));
    }

    public function testAPercentSignIsACharacterNotAWildcard(): void
    {
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont SARL', 'status' => ClientStatus::Active]);

        self::assertSame([], $this->search('%'));
        self::assertSame([], $this->search('a_e'));
    }

    public function testNeverReachesAnotherCompany(): void
    {
        $filters = $this->em->getFilters();
        $filters->disable('company');
        $other = CompanyFactory::createOne(['name' => 'Autre Inc']);
        $theirs = ClientFactory::createOne(['company' => $other, 'name' => 'Dupont Concurrent', 'status' => ClientStatus::Active]);
        InvoiceFactory::createOne(['company' => $other, 'client' => $theirs, 'status' => InvoiceStatus::Pending]);
        $filters->enable('company');

        ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont SARL', 'status' => ClientStatus::Active]);
        $this->em->clear();

        $results = $this->search('dupont');

        self::assertSame(['clients'], array_keys($results));
        self::assertSame(['Atelier Dupont SARL'], $this->titles($results['clients']));
    }

    public function testTheSearchBarIsThereAndAnswersWithoutMeilisearch(): void
    {
        ClientFactory::createOne(['company' => $this->company, 'name' => 'Atelier Dupont SARL', 'status' => ClientStatus::Active]);
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $json = $this->browser()
            ->actingAs($user)
            ->visit('/clients/')
            ->assertSuccessful()
            ->assertSeeElement('.global-search[data-global-search-advanced-value="false"]')
            ->assertNotSeeElement('.search-help-trigger')
            ->visit('/search?q=dupont')
            ->assertSuccessful()
            ->content();

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Atelier Dupont SARL', $payload['clients'][0]['title']);
    }

    /**
     * @return array<string, list<SearchResult>>
     */
    private function search(string $text): array
    {
        self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

        return self::getContainer()->get(DatabaseSearchService::class)->search(new ParsedQuery($text));
    }

    /**
     * @param list<SearchResult> $results
     *
     * @return list<string>
     */
    private function titles(array $results): array
    {
        return array_map(static fn (SearchResult $result): string => $result->title, $results);
    }
}
