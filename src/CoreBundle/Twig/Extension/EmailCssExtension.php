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

use Twig\Attribute\AsTwigFilter;
use function implode;
use function preg_match;
use function preg_replace;
use function strlen;
use function substr;

/**
 * `inline_css` copies each rule onto the elements it matches, and a rule
 * inside a media query matches nothing it can copy, so it is dropped. The
 * email layouts then kept their 580px desktop width on a phone.
 *
 * `css|media_queries` keeps only those blocks, for a `<style>` of their own
 * in the email's head, where mail clients that read media queries find them.
 *
 * @see \Augias\CoreBundle\Tests\Twig\Extension\EmailCssExtensionTest
 */
final class EmailCssExtension
{
    #[AsTwigFilter('media_queries')]
    public function mediaQueries(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $blocks = [];
        $offset = 0;

        while (1 === preg_match('/@media[^{]*\{/', $css, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $depth = 0;
            $length = strlen($css);

            for ($i = $start + strlen($match[0][0]) - 1; $i < $length; ++$i) {
                if ('{' === $css[$i]) {
                    ++$depth;
                } elseif ('}' === $css[$i] && 0 === --$depth) {
                    break;
                }
            }

            $blocks[] = substr($css, $start, $i - $start + 1);
            $offset = $i + 1;
        }

        return implode("\n", $blocks);
    }
}
