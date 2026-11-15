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

namespace Augias\CoreBundle\Search;

use SolidWorx\Toggler\ToggleInterface;

/**
 * Meilisearch when it is configured, the database otherwise: the search bar
 * is there either way.
 */
final readonly class ToggledSearchBackend implements SearchBackendInterface
{
    public function __construct(
        private MultiSearchService $meilisearch,
        private DatabaseSearchService $database,
        private ToggleInterface $toggle,
    ) {
    }

    public function search(ParsedQuery $parsedQuery): array
    {
        return $this->toggle->isActive('meilisearch_search')
            ? $this->meilisearch->search($parsedQuery)
            : $this->database->search($parsedQuery);
    }
}
