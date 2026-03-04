<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TokenEncryptor
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $value): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return $value; // Legacy plaintext token
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if ($plaintext === false) {
            return $value; // Decryption failed — legacy plaintext token
        }

        return $plaintext;
    }
}
