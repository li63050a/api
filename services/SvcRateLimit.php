<?php
/**
 * 限流服务：基于文件锁的 best-effort 每分钟计数。
 */
class SvcRateLimit
{
    public function check(int $keyId, int $limit): bool
    {
        $dir = config('log_dir') . '/ratelimit';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $window = date('YmdHI');
        $file = $dir . '/rl_' . $keyId . '_' . $window;
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            // 文件不可用：放行，best-effort
            return true;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        $content = (string) stream_get_contents($fp);
        $count = is_numeric($content) ? (int) $content : 0;
        $count++;

        if ($count > $limit) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $count);
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $count);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
}
