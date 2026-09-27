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

namespace Augias\CoreBundle\Twig\Extension;

use Augias\CoreBundle\Config\DesignConfigProvider;
use Augias\SettingsBundle\SystemConfig;
use Twig\Attribute\AsTwigFunction;
use function hexdec;
use function implode;
use function max;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_split;
use function strtoupper;
use function substr;
use function substr_count;
use function trim;

/**
 * What a company's documents carry of its own: a brand colour, and a page
 * footer with free text and the bank details clients pay to.
 *
 * Everything is read defensively: a colour that is not one, or an empty
 * field, leaves the template exactly as it was.
 *
 * @see \Augias\CoreBundle\Tests\Functional\DocumentBrandingTest
 */
final readonly class BrandExtension
{
    /** Room the page footer takes with no text of the company's own. */
    private const int BASE_FOOTER_MM = 25;

    private const int MM_PER_LINE = 4;

    public function __construct(
        private SystemConfig $systemConfig,
    ) {
    }

    /**
     * `brand_color()`: the company's accent, "#rrggbb", or null to keep the
     * template's own colours.
     */
    #[AsTwigFunction('brand_color')]
    public function brandColor(): ?string
    {
        $color = trim((string) $this->systemConfig->get(DesignConfigProvider::ACCENT_COLOR));

        if (1 === preg_match('/^#?([0-9a-fA-F]{3}){1,2}$/', $color)) {
            $hex = ltrim($color, '#');

            if (3 === strlen($hex)) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return '#' . strtolower($hex);
        }

        return null;
    }

    /**
     * `brand_text_color()`: white or near-black, whichever reads on the brand
     * colour (WCAG relative luminance).
     */
    #[AsTwigFunction('brand_text_color')]
    public function brandTextColor(): string
    {
        $color = $this->brandColor();

        if (null === $color) {
            return '#ffffff';
        }

        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.45 ? '#1e293b' : '#ffffff';
    }

    /**
     * `document_footer()`: what the company prints at the foot of every page.
     *
     * @return array{text: ?string, iban: ?string, bic: ?string}
     */
    #[AsTwigFunction('document_footer')]
    public function documentFooter(): array
    {
        $text = trim((string) $this->systemConfig->get(DesignConfigProvider::FOOTER_TEXT));
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $this->systemConfig->get(DesignConfigProvider::IBAN)));
        $bic = strtoupper(trim((string) $this->systemConfig->get(DesignConfigProvider::BIC)));

        return [
            'text' => '' === $text ? null : mb_substr($text, 0, 400),
            // Grouped by four, as it is printed on a RIB.
            'iban' => '' === $iban ? null : implode(' ', str_split($iban, 4)),
            'bic' => '' === $bic || '' === $iban ? null : $bic,
        ];
    }

    /**
     * `pdf_footer_margin()`: the page's bottom margin, grown by the lines
     * the footer holds, so the body never runs under it.
     */
    #[AsTwigFunction('pdf_footer_margin')]
    public function pdfFooterMargin(): string
    {
        $footer = $this->documentFooter();
        $lines = 0;

        if (null !== $footer['text']) {
            // A line break, or roughly every 130 characters at this size.
            $lines += max(substr_count($footer['text'], "\n") + 1, (int) ceil(mb_strlen($footer['text']) / 130));
        }

        if (null !== $footer['iban']) {
            ++$lines;
        }

        return sprintf('%dmm', self::BASE_FOOTER_MM + $lines * self::MM_PER_LINE);
    }
}
