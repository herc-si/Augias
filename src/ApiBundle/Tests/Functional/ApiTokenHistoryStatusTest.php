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

namespace Augias\ApiBundle\Tests\Functional;

use Augias\ApiBundle\Test\ApiTestCase;
use Augias\ClientBundle\Entity\Client;
use Augias\UserBundle\Entity\ApiTokenHistory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Ulid;

/**
 * The token's request history showed "-" for every status: the entry was made
 * at authentication, before any response (30/09/2026).
 */
#[Group('functional')]
final class ApiTokenHistoryStatusTest extends ApiTestCase
{
    protected function getResourceClass(): string
    {
        return Client::class;
    }

    public function testEachRequestIsRecordedWithTheStatusItWasAnsweredWith(): void
    {
        self::$client->request('GET', '/api/clients', ['headers' => ['accept' => 'application/ld+json']]);
        self::assertResponseIsSuccessful();

        self::$client->request('GET', '/api/clients/' . new Ulid(), ['headers' => ['accept' => 'application/ld+json']]);
        self::assertResponseStatusCodeSame(404);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getFilters()->disable('company');
        $entityManager->clear();

        $statuses = [];
        foreach ($entityManager->getRepository(ApiTokenHistory::class)->findAll() as $history) {
            $statuses[$history->getResource()] = $history->getStatusCode();
        }

        self::assertSame(200, $statuses['/api/clients'] ?? null);
        self::assertContains(404, $statuses);
    }
}
