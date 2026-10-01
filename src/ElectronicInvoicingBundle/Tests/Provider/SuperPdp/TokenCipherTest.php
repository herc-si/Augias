<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp;

use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\TokenCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function base64_decode;
use function base64_encode;
use function str_contains;

#[CoversClass(TokenCipher::class)]
final class TokenCipherTest extends TestCase
{
    public function testWhatIsSealedComesBack(): void
    {
        $cipher = new TokenCipher('app-secret');

        $sealed = $cipher->encrypt('a-refresh-token');

        self::assertFalse(str_contains($sealed, 'a-refresh-token'));
        self::assertSame('a-refresh-token', $cipher->decrypt($sealed));
    }

    public function testTheSameTokenIsNeverSealedTheSameWay(): void
    {
        $cipher = new TokenCipher('app-secret');

        self::assertNotSame($cipher->encrypt('a-token'), $cipher->encrypt('a-token'));
    }

    /**
     * The key comes from the application secret: change it, and what was
     * sealed before cannot be read — the companies connect again.
     */
    public function testAnotherApplicationSecretCannotReadIt(): void
    {
        $sealed = new TokenCipher('app-secret')->encrypt('a-token');

        self::assertNull(new TokenCipher('another-secret')->decrypt($sealed));
    }

    public function testATamperedValueIsNotRead(): void
    {
        $cipher = new TokenCipher('app-secret');
        $raw = (string) base64_decode($cipher->encrypt('a-token'), true);
        $raw[30] = $raw[30] === 'x' ? 'y' : 'x';

        self::assertNull($cipher->decrypt(base64_encode($raw)));
        self::assertNull($cipher->decrypt('not base64 at all!'));
        self::assertNull($cipher->decrypt(''));
    }
}
