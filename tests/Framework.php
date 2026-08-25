<?php
declare(strict_types=1);

namespace Tests;

final class Framework
{
    /** @var array<int, array{name:string, pass:bool, msg:string}> */
    private static array $results = [];

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$results[] = ['name' => $name, 'pass' => true, 'msg' => ''];
        } catch (\Throwable $e) {
            self::$results[] = ['name' => $name, 'pass' => false, 'msg' => $e->getMessage()];
        }
    }

    public static function assertTrue(bool $cond, string $msg = 'expected true'): void
    {
        if (!$cond) { throw new \RuntimeException($msg); }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
        }
    }

    public static function assertContains(mixed $needle, array $haystack): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException('expected to contain ' . var_export($needle, true));
        }
    }

    public static function assertThrows(callable $fn, string $class, string $msg = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) { return; }
            throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . $class . ' got ' . get_class($e));
        }
        throw new \RuntimeException(($msg !== '' ? $msg . ' | ' : '') . 'expected ' . $class . ' to be thrown');
    }

    public static function summary(): int // 返回失败数
    {
        $fail = 0;
        foreach (self::$results as $r) {
            $mark = $r['pass'] ? 'PASS' : 'FAIL';
            printf("[%s] %s%s\n", $mark, $r['name'], $r['pass'] ? '' : ' — ' . $r['msg']);
            if (!$r['pass']) { $fail++; }
        }
        printf("\n%d/%d tests passed\n", count(self::$results) - $fail, count(self::$results));
        return $fail;
    }
}
