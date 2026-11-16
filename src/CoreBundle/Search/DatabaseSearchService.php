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

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\RecurringInvoice;
use Augias\PaymentBundle\Entity\Payment;
use Augias\QuoteBundle\Entity\Quote;
use Doctrine\ORM\EntityManagerInterface;
use Meilisearch\Bundle\SearchableObject;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Ulid;
use function array_map;
use function in_array;
use function is_array;
use function mb_strtolower;
use function sprintf;
use function strtr;

/**
 * The search bar without Meilisearch: a plain "contains" search in the
 * database, for the installations that do not run a search engine beside the
 * application — which is most of them.
 *
 * It answers in the shape Meilisearch does. Each record found is normalised
 * with the same `searchable` group the index is fed with and handed to the
 * same result formatter, so the bar shows the same lines whichever backend
 * answered.
 *
 * What it does not do: typo tolerance, and the qualifiers (`status:`,
 * `amount:`, `created:`, `sort:`). `in:` works. The bar does not offer the
 * qualifiers when this backend answers.
 *
 * The company and archive filters of Doctrine apply to every query here, as
 * they do everywhere else.
 *
 * @see \Augias\CoreBundle\Tests\Search\DatabaseSearchServiceTest
 */
final class DatabaseSearchService implements SearchBackendInterface
{
    /**
     * Per index: the entity, the joins the search needs, and the columns it looks in.
     *
     * @var array<string, array{class: class-string, joins: array<string, string>, fields: list<string>}>
     */
    private const array INDICES = [
        'clients' => ['class' => Client::class, 'joins' => [], 'fields' => ['e.name', 'e.website']],
        'contacts' => ['class' => Contact::class, 'joins' => [], 'fields' => ['e.firstName', 'e.lastName', 'e.email']],
        'invoices' => ['class' => Invoice::class, 'joins' => ['e.client' => 'c'], 'fields' => ['e.invoiceId', 'e.subject', 'c.name']],
        'recurring_invoices' => ['class' => RecurringInvoice::class, 'joins' => ['e.client' => 'c'], 'fields' => ['e.subject', 'c.name']],
        'quotes' => ['class' => Quote::class, 'joins' => ['e.client' => 'c'], 'fields' => ['e.quoteId', 'e.subject', 'c.name']],
        'payments' => ['class' => Payment::class, 'joins' => ['e.client' => 'c'], 'fields' => ['e.reference', 'c.name']],
    ];

    /**
     * @var array<string, ResultFormatterInterface>|null
     */
    private ?array $formatterMap = null;

    /**
     * @param iterable<ResultFormatterInterface> $formatters
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NormalizerInterface $normalizer,
        private readonly CompanySelector $companySelector,
        private readonly iterable $formatters,
    ) {
    }

    public function search(ParsedQuery $parsedQuery): array
    {
        if (! $this->companySelector->getCompany() instanceof Ulid || '' === $parsedQuery->fulltext) {
            return [];
        }

        $formatters = $this->formatters();
        // LIKE has its own wildcards: a "%" typed in the bar is a character to find, not a
        // pattern. Escaped with "!" rather than a backslash, which MySQL also reads as the
        // escape character of the string literal itself.
        $pattern = '%' . strtr(mb_strtolower($parsedQuery->fulltext), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $grouped = [];

        foreach (self::INDICES as $index => $definition) {
            if (! isset($formatters[$index]) || ([] !== $parsedQuery->indices && ! in_array($index, $parsedQuery->indices, true))) {
                continue;
            }

            $builder = $this->entityManager->createQueryBuilder()->select('e')->from($definition['class'], 'e');

            foreach ($definition['joins'] as $association => $alias) {
                $builder->leftJoin($association, $alias);
            }

            $builder
                ->where($builder->expr()->orX(...array_map(
                    static fn (string $field): string => sprintf("LOWER(%s) LIKE :pattern ESCAPE '!'", $field),
                    $definition['fields'],
                )))
                ->setParameter('pattern', $pattern)
                // Ids are ULIDs: the most recent records first.
                ->orderBy('e.id', 'DESC')
                ->setMaxResults($parsedQuery->hitsPerIndex);

            $results = [];

            foreach ($builder->getQuery()->getResult() as $entity) {
                $hit = $this->normalizer->normalize($entity, SearchableObject::NORMALIZATION_FORMAT, ['groups' => ['searchable']]);

                if (is_array($hit) && isset($hit['id'])) {
                    $results[] = $formatters[$index]->format($hit);
                }
            }

            if ([] !== $results) {
                $grouped[$index] = $results;
            }
        }

        return $grouped;
    }

    /**
     * @return array<string, ResultFormatterInterface>
     */
    private function formatters(): array
    {
        if (null === $this->formatterMap) {
            $this->formatterMap = [];

            foreach ($this->formatters as $formatter) {
                $this->formatterMap[$formatter->getIndexName()] = $formatter;
            }
        }

        return $this->formatterMap;
    }
}
