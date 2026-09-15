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

namespace Augias\SettingsBundle\Tests\Twig\Components;

use Augias\CoreBundle\Test\LiveComponentTest;
use Augias\SettingsBundle\Twig\Components\Settings;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use function preg_match_all;
use function sprintf;

/**
 * A key with no entry in the catalogue renders as the key itself, and the
 * settings screen is where that shows most: its help text comes out of the
 * database, so a key stored there and missing from the catalogue is a line of
 * `invoice.settings.watermark.description` on the page.
 *
 * Nothing catches that on its own — the page renders, the test suite is green,
 * and only a reader notices.
 */
#[CoversNothing]
final class SettingsHasNoUntranslatedKeyTest extends LiveComponentTest
{
    private TestLiveComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->component = $this->createLiveComponent(name: Settings::class, client: $this->client)
            ->actingAs($this->getUser());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sections(): iterable
    {
        foreach (['system', 'invoice', 'quote', 'email', 'design', 'accounting'] as $section) {
            yield $section => [$section];
        }
    }

    #[DataProvider('sections')]
    public function testNoSectionShowsATranslationKeyToTheReader(string $section): void
    {
        $this->ensureSessionIsSet();

        $this->component->set('section', $section);

        $html = (string) $this->component->render();

        // Text nodes only: attributes are full of dotted values that are not
        // translation keys at all — class names, icon names, hostnames.
        preg_match_all('#>\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+){2,})\s*<#', $html, $matches);

        self::assertSame(
            [],
            $matches[1],
            sprintf('The "%s" section shows translation keys instead of text.', $section),
        );
    }
}
