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

use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Override;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A test instance says so on every page, the sign-in page first; the one
 * clients use shows nothing.
 */
#[Group('functional')]
final class InstanceLabelTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testALabelledInstanceWearsItsLabel(): void
    {
        $content = $this->signInPage('TEST');

        self::assertStringContainsString('<div class="instance-label" role="status"><span>TEST</span></div>', $content);
        self::assertStringContainsString('document.title = "[TEST] " + document.title;', $content);
    }

    public function testAnUnlabelledInstanceShowsNothing(): void
    {
        self::assertStringNotContainsString('instance-label', $this->signInPage(null));
    }

    private function signInPage(?string $label): string
    {
        if ($label === null) {
            unset($_SERVER['AUGIAS_INSTANCE_LABEL'], $_ENV['AUGIAS_INSTANCE_LABEL']);
        } else {
            $_SERVER['AUGIAS_INSTANCE_LABEL'] = $_ENV['AUGIAS_INSTANCE_LABEL'] = $label;
        }

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_SERVER['AUGIAS_INSTANCE_LABEL'], $_ENV['AUGIAS_INSTANCE_LABEL']);
        parent::tearDown();
    }
}
