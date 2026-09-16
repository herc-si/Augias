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
use Augias\SettingsBundle\Repository\SettingsRepository;
use Augias\SettingsBundle\Twig\Components\Settings;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use function array_keys;
use function preg_match_all;
use function sprintf;
use function strstr;

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

    public function testNoSectionShowsATranslationKeyToTheReader(): void
    {
        $shown = [];

        foreach ($this->sections() as $section) {
            $this->ensureSessionIsSet();

            $this->component->set('section', $section);

            $html = (string) $this->component->render();

            // Text nodes only: attributes are full of dotted values that are not
            // translation keys at all — class names, icon names, hostnames.
            preg_match_all('#>\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+){2,})\s*<#', $html, $matches);

            foreach ($matches[1] as $key) {
                $shown[] = sprintf('%s: %s', $section, $key);
            }
        }

        self::assertSame([], $shown, 'The settings screen shows translation keys instead of text.');
    }

    /**
     * The sections are whatever the first segment of a setting key happens to
     * be, so a bundle that seeds `credit_note/...` gets a tab whether or not
     * anyone taught the page about it. Reading them back instead of naming them
     * is the point: the list here used to be written out by hand, `credit_note`
     * was never added to it, and that section reached the screen with an
     * English tab title and a raw form dump under it.
     *
     * @return list<string>
     */
    private function sections(): array
    {
        $sections = [];

        foreach (self::getContainer()->get(SettingsRepository::class)->findAll() as $setting) {
            $sections[strstr($setting->getKey(), '/', true) ?: $setting->getKey()] = true;
        }

        return array_keys($sections);
    }
}
