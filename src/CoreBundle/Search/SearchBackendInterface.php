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

/**
 * Something that can answer the search bar: Meilisearch where it is
 * installed, the database where it is not.
 */
interface SearchBackendInterface
{
    /**
     * @return array<string, list<SearchResult>> keyed by logical index name
     */
    public function search(ParsedQuery $parsedQuery): array;
}
