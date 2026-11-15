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

namespace Augias\CoreBundle\Action;

use Augias\CoreBundle\Search\SearchBackendInterface;
use Augias\CoreBundle\Search\SearchQueryParser;
use Augias\CoreBundle\Search\SearchResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function array_map;
use function strlen;
use function trim;

/**
 * @see \Augias\CoreBundle\Tests\Action\SearchTest
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class Search
{
    public function __construct(
        private SearchBackendInterface $searchService,
        private SearchQueryParser $queryParser,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $raw = trim((string) $request->query->get('q', ''));

        if (strlen($raw) < 2) {
            return new JsonResponse([]);
        }

        $parsed = $this->queryParser->parse($raw);
        $results = $this->searchService->search($parsed);

        $serialized = [];
        foreach ($results as $indexName => $typeResults) {
            $serialized[$indexName] = array_map(
                static fn (SearchResult $r) => (array) $r,
                $typeResults,
            );
        }

        return new JsonResponse($serialized);
    }
}
