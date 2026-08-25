<?php
declare(strict_types=1);

namespace Tests\Test;

use App\Domain\Crypto\CryptoService;
use App\Support\Config;
use RuntimeException;
use Tests\Framework;

Framework::test('CryptoService: encrypt/decrypt round-trip', function (): void {
    $svc = new CryptoService('0123456789abcdef0123456789abcdef');
    $plain = '{"hello":"世界"}';
    $enc = $svc->encrypt($plain);
    Framework::assertTrue($enc !== $plain, 'cipher differs from plain');
    Framework::assertSame($plain, $svc->decrypt($enc));
});

Framework::test('CryptoService: decrypt tampered fails', function (): void {
    $svc = new CryptoService('0123456789abcdef0123456789abcdef');
    $enc = $svc->encrypt('secret');
    Framework::assertThrows(
        fn () => $svc->decrypt(substr($enc, 0, -2) . 'ab'),
        RuntimeException::class
    );
});
