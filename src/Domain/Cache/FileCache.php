<?php
declare(strict_types=1);

namespace App\Domain\Cache;

final class FileCache
{
    public function __construct(private string $dir) {}

    public function get(string $key): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }
        $arr = json_decode($data, true);
        if (!is_array($arr) || !isset($arr['exp'], $arr['val'])) {
            return null;
        }
        if ($arr['exp'] !== 0 && $arr['exp'] < time()) {
            @unlink($file);
            return null;
        }
        return $arr['val'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return;
        }
        $payload = ['exp' => $ttlSeconds < 0 ? time() - 1 : time() + $ttlSeconds, 'val' => $value];
        $tmp = $this->file($key) . '.tmp';
        @file_put_contents($tmp, json_encode($payload));
        @rename($tmp, $this->file($key));
    }

    public function delete(string $key): void
    {
        @unlink($this->file($key));
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }
}
