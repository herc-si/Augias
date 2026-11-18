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

namespace Augias\McpBundle\Tests\OAuth;

use Augias\McpBundle\OAuth\KeyManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use function file_get_contents;
use function fileperms;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(KeyManager::class)]
final class KeyManagerTest extends TestCase
{
    private string $dir;

    #[Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/augias-keys-' . uniqid();
    }

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testTheKeysAreCreatedTheFirstTimeTheyAreNeeded(): void
    {
        $keys = new KeyManager($this->dir, 'secret');
        self::assertFalse($keys->hasKeys());

        $keys->getPublicKey();

        self::assertTrue($keys->hasKeys());
        self::assertSame(0600, fileperms($keys->getPrivateKeyPath()) & 0777);
    }

    public function testExistingKeysAreKept(): void
    {
        $keys = new KeyManager($this->dir, 'secret');
        $keys->generate();
        $before = file_get_contents($keys->getPrivateKeyPath());

        $keys->getPrivateKey();

        self::assertSame($before, file_get_contents($keys->getPrivateKeyPath()));
    }
}
