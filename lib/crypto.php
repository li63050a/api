<?php
/**
 * 加解密（用于上游 Key 存储）。密钥在 data/.key，安装时生成。
 */
function crypto_key(): string
{
    static $k = null;
    if ($k === null) {
        $path = dirname(config('db_path')) . '/.key';
        if (!is_file($path)) {
            $k = base64_encode(random_bytes(32));
            file_put_contents($path, $k);
            chmod($path, 0600);
        } else {
            $k = file_get_contents($path);
        }
    }
    return base64_decode($k);
}

function crypto_encrypt(string $plain): string
{
    $iv = random_bytes(16);
    $c = openssl_encrypt($plain, 'AES-256-CBC', crypto_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $c);
}

function crypto_decrypt(string $payload): string
{
    $raw = base64_decode($payload);
    $iv = substr($raw, 0, 16);
    $c = substr($raw, 16);
    return openssl_decrypt($c, 'AES-256-CBC', crypto_key(), OPENSSL_RAW_DATA, $iv);
}
