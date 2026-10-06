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

namespace Augias\CoreBundle\Generator\BillingIdGenerator;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function assert;
use function ctype_digit;
use function max;
use function strlen;
use function substr;

/**
 * @see \Augias\CoreBundle\Tests\Generator\BillingIdGenerator\AutoIncrementIdGeneratorTest
 */
#[AsTaggedItem('auto_increment')]
final readonly class AutoIncrementIdGenerator implements SequentialIdGeneratorInterface
{
    public function __construct(
        private ManagerRegistry $registry
    ) {
    }

    public static function getName(): string
    {
        return 'auto_increment';
    }

    public function getConfigurationFormType(): ?string
    {
        return null;
    }

    public function generate(object $entity, array $options): string
    {
        $em = $this->registry->getManagerForClass($entity::class);
        assert($em instanceof EntityManager);

        $filters = $em->getFilters();

        $filters->disable('archivable');

        try {
            $prefix = (string) ($options['prefix'] ?? '');
            $suffix = (string) ($options['suffix'] ?? '');
            $affixes = strlen($prefix) + strlen($suffix);

            /** @var list<string|null> $ids */
            $ids = $this->registry
                ->getRepository($entity::class)
                ->createQueryBuilder('e')
                ->select('e.' . $options['field'])
                ->getQuery()
                ->getSingleColumnResult();
        } finally {
            $filters->enable('archivable');
        }

        // Read here rather than cut and cast in SQL: a number from before the
        // prefix was set, shorter than it or not a number once cut, made
        // PostgreSQL refuse the whole query (negative substring length,
        // invalid numeric input) and creating a quote a 500 — preprod,
        // 06/10/2026. Such a number says nothing about the next one.
        $lastId = 0;

        foreach ($ids as $id) {
            $id = (string) $id;
            $number = substr($id, strlen($prefix), strlen($id) - $affixes);

            if (strlen($id) <= $affixes || '' === $number || ! ctype_digit($number)) {
                continue;
            }

            $lastId = max($lastId, (int) $number);
        }

        return (string) ($lastId + 1);
    }
}
