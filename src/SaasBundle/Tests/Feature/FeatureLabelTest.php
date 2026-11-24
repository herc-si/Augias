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

namespace Augias\SaasBundle\Tests\Feature;

use Augias\SaasBundle\Feature\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use function sprintf;

/**
 * Every right of a plan has a label for customers, in French and English:
 * without one the plans page shows its key, as assistant_questions_per_month
 * did on 24/11/2026.
 */
final class FeatureLabelTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Feature, string}>
     */
    public static function rights(): iterable
    {
        foreach (Feature::cases() as $feature) {
            foreach (['fr', 'en'] as $locale) {
                yield $feature->value . ' (' . $locale . ')' => [$feature, $locale];
            }
        }
    }

    #[DataProvider('rights')]
    public function testEveryRightHasALabel(Feature $feature, string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $key = 'saas.plan.feature.' . $feature->value;

        self::assertNotSame($key, $translator->trans($key, [], null, $locale), sprintf('No %s label for the right "%s": add %s to messages.%s.yml.', $locale, $feature->value, $key, $locale));
    }
}
