<?php

declare(strict_types=1);

namespace Phjs;

final class Logger
{
    public static function info(string $msg): void
    {
        self::line('info', "\033[1;34m", $msg);
    }

    public static function ok(string $msg): void
    {
        self::line('ok', "\033[1;32m", $msg);
    }

    public static function warn(string $msg): void
    {
        self::line('warn', "\033[1;33m", $msg);
    }

    public static function error(string $msg): void
    {
        self::line('error', "\033[1;31m", $msg);
    }

    private static function line(string $tag, string $color, string $msg): void
    {
        $err = defined('STDERR') ? STDERR : (fopen('php://stderr', 'w') ?: null);
        $tty = $err !== null && function_exists('posix_isatty') && posix_isatty($err);
        $line = ($tty ? $color . '[' . str_pad($tag, 5) . ']' . "\033[0m $msg" : '[' . str_pad($tag, 5) . '] ' . $msg) . "\n";
        if ($err !== null) {
            fwrite($err, $line);
        } elseif (function_exists('error_log')) {
            error_log('phjs: ' . $line);
        }
    }
}
