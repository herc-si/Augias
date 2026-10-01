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

namespace Augias\ElectronicInvoicingBundle\Provider\SuperPdp;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function base64_decode;
use function base64_encode;
use function random_bytes;
use function sodium_crypto_generichash;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function strlen;
use function substr;

/**
 * Keeps a company's SUPER PDP tokens unreadable in the database: whoever gets
 * a copy of it — a backup, an export, a support visit — does not get to send
 * invoices in the company's name for a year.
 *
 * The key comes from the application secret. Changing that secret makes the
 * stored tokens unreadable, and the companies connect again.
 *
 * @see \Augias\ElectronicInvoicingBundle\Tests\Provider\SuperPdp\TokenCipherTest
 */
final readonly class TokenCipher
{
    private string $key;

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
    ) {
        $this->key = sodium_crypto_generichash('augias.einvoicing.super_pdp_tokens' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * Null when it cannot be read: tampered with, or sealed under another
     * application secret.
     */
    public function decrypt(string $ciphertext): ?string
    {
        $raw = base64_decode($ciphertext, true);

        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return false === $plaintext ? null : $plaintext;
    }
}
