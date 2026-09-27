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

namespace Augias\CoreBundle\Tests\Functional;

use const JSON_THROW_ON_ERROR;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function file_exists;
use function file_get_contents;
use function getimagesize;
use function json_decode;

/**
 * What makes Augias installable as an app: a manifest with icons that exist,
 * and a service worker that the pages register. Chrome's own verdict was
 * checked by hand (no installability error); this keeps the pieces in place.
 */
#[Group('functional')]
final class PwaTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testTheManifestPointsAtIconsThatExist(): void
    {
        $public = self::getContainer()->getParameter('kernel.project_dir') . '/public';
        $manifest = json_decode((string) file_get_contents($public . '/manifest.json'), true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame('Augias', $manifest['name']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['start_url']);

        $purposes = [];

        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists($public . $icon['src']);
            $size = getimagesize($public . $icon['src']);
            self::assertIsArray($size);
            self::assertSame($icon['sizes'], $size[0] . 'x' . $size[1]);
            $purposes[$icon['purpose'] . ' ' . $icon['sizes']] = true;
        }

        // What Chrome asks for, and what Android crops to its own shape.
        self::assertArrayHasKey('any 192x192', $purposes);
        self::assertArrayHasKey('any 512x512', $purposes);
        self::assertArrayHasKey('maskable 512x512', $purposes);

        self::assertTrue(file_exists($public . '/sw.js') && file_exists($public . '/offline.html'));
    }

    public function testEveryPageLinksTheManifestAndRegistersTheWorker(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeElement('link[rel="manifest"][href="/manifest.json"]')
            ->assertSeeElement('link[rel="apple-touch-icon"]')
            ->assertContains("navigator.serviceWorker.register('/sw.js')");
    }

    /**
     * Nothing a company owns is kept on the phone: the worker caches its
     * offline page and one icon, never a page.
     */
    public function testTheWorkerCachesNoPage(): void
    {
        $worker = (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/public/sw.js');

        self::assertStringContainsString("cache.addAll([OFFLINE, '/icons/icon-192.png'])", $worker);
        self::assertStringNotContainsString('cache.put', $worker);
    }
}
