<?php
declare(strict_types=1);

namespace App\Domain\Crypto;

use RuntimeException;

final class CryptoService
{
    public function __construct(private string $key) {}

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('encrypt failed');
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            throw new RuntimeException('invalid cipher payload');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('decrypt failed (tampered?)');
        }
        return $plain;
    }
}
