<?php
declare(strict_types=1);

namespace App\Domain\RateLimit;

final class FileRateLimiter
{
    public function __construct(private string $dir) {}

    /** @return bool 本次消费是否被允许 */
    public function consume(string $key, int $limit, int $windowSeconds): bool
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
            return true; // 无法写入时放行，避免误伤
        }
        $file = $this->dir . '/' . hash('sha256', $key) . '.rl';
        $now = time();
        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return true;
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $data = $raw === false || $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data) || ($data['window'] ?? 0) !== intdiv($now, $windowSeconds)) {
            $data = ['window' => intdiv($now, $windowSeconds), 'count' => 0];
        }
        if ($data['count'] >= $limit) {
            fclose($fh);
            return false;
        }
        $data['count']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return true;
    }
}
