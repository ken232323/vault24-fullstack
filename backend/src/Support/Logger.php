<?php
declare(strict_types=1);

namespace Vault24\Support;

/** Application/debug log. Never receives request bodies, headers, tokens or passwords. */
final class Logger
{
    public function __construct(private string $path) {}

    public function error(string $requestId, \Throwable $e): void
    {
        $this->write(sprintf('[%s] ERROR rid=%s %s: %s at %s:%d', gmdate('c'), $requestId, $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine()));
    }

    public function notice(string $message): void
    {
        $this->write(sprintf('[%s] NOTICE %s', gmdate('c'), $message));
    }

    private function write(string $line): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($this->path, str_replace(["\r", "\n"], ' ', $line) . "\n", FILE_APPEND | LOCK_EX);
    }
}
