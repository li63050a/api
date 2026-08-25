<?php
declare(strict_types=1);

namespace App\Support;

final class Notifier
{
    public function __construct(private Config $config) {}

    /** @param array<int, string> $lines */
    public function alert(string $subject, array $lines): void
    {
        $to = $this->config->get('notify_email');
        if (empty($to)) {
            return;
        }
        $body = implode("\n", $lines);
        $headers = "From: " . $this->config->get('notify_from', 'aiapi@localhost') . "\r\n";
        @mail($to, '[AIAPI] ' . $subject, $body, $headers);
    }
}
