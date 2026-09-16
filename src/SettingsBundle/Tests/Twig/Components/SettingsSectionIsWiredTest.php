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
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use function array_keys;
use function sprintf;
use function str_contains;
use function strstr;

/**
 * The settings screen takes its tabs from whatever sections exist in the
 * database, but decides their titles and their layout from a list of section
 * names written into the template. A section the template has never heard of
 * still gets a tab — it just gets the wrong one. The title falls back to the
 * section name humanised into English, which `|trans` cannot translate because
 * it is not a key, and the body falls through to a bare `form_widget`, whose
 * labels Symfony humanises into English too.
 *
 * `credit_note` reached the screen that way, and nothing failed: the page
 * rendered, the help text was translated, and the untranslated-key test beside
 * this one stayed green because "Credit Note" is not a key. Only a reader
 * noticed.
 */
#[CoversNothing]
final class SettingsSectionIsWiredTest extends LiveComponentTest
{
    private TestLiveComponent $component;

    protected function setUp(): void
    {
        parent::setUp();

        $this->component = $this->createLiveComponent(name: Settings::class, client: $this->client)
            ->actingAs($this->getUser());
    }

    /**
     * Both catalogues, not just the default one: a title present in English and
     * missing in French reads as English to the reader who asked for French,
     * which is the whole complaint.
     */
    public function testEverySectionHasATabTitleOfItsOwn(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        $missing = [];

        foreach ($this->sections() as $section) {
            $key = sprintf('settings.page.tab.%s', $section);

            foreach (['en', 'fr'] as $locale) {
                if ($translator->trans($key, locale: $locale) === $key) {
                    $missing[] = sprintf('%s (%s)', $key, $locale);
                }
            }
        }

        self::assertSame([], $missing, 'A section with no tab title falls back to its own name, in English.');
    }

    /**
     * A section that numbers documents gets the numbering layout: a heading, an
     * explanation, and a preview of the number that will actually be issued.
     * The default rendering has none of them, so the heading is what tells the
     * real page apart from a raw form dump.
     */
    public function testASectionThatNumbersDocumentsShowsTheNumberingLayout(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $heading = $translator->trans('settings.page.numbering.title');

        $sections = $this->sectionsThatNumberDocuments();

        self::assertNotEmpty($sections, 'No section numbers anything, so this test proves nothing.');

        $without = [];

        foreach ($sections as $section) {
            $this->ensureSessionIsSet();

            $this->component->set('section', $section);

            if (! str_contains((string) $this->component->render(), $heading)) {
                $without[] = $section;
            }
        }

        self::assertSame([], $without, 'These sections number documents but fall through to the default rendering.');
    }

    /**
     * @return list<string>
     */
    private function sections(): array
    {
        return array_keys($this->settingKeysBySection());
    }

    /**
     * @return list<string>
     */
    private function sectionsThatNumberDocuments(): array
    {
        $sections = [];

        foreach ($this->settingKeysBySection() as $section => $keys) {
            foreach ($keys as $key) {
                if (str_contains($key, '/id_generation/')) {
                    $sections[] = $section;

                    break;
                }
            }
        }

        return $sections;
    }

    /**
     * @return array<string, list<string>>
     */
    private function settingKeysBySection(): array
    {
        $sections = [];

        foreach (self::getContainer()->get(SettingsRepository::class)->findAll() as $setting) {
            $key = $setting->getKey();
            $sections[strstr($key, '/', true) ?: $key][] = $key;
        }

        return $sections;
    }
}
