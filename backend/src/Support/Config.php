<?php
declare(strict_types=1);

namespace Vault24\Support;

/** Immutable configuration. Secrets/env come from the environment or an untracked .env file. */
final class Config
{
    private function __construct(private array $values) {}

    public static function defaults(string $root): array
    {
        return [
            'root' => $root,
            'env' => 'production',            // production | development | testing
            'db_path' => $root . '/storage/vault24.sqlite',
            'log_path' => $root . '/storage/logs/app.log',
            'cors_origins' => [],             // explicit allowlist, e.g. ["https://app.example.test"]
            'trusted_proxies' => [],          // only these REMOTE_ADDRs may set X-Forwarded-For
            'cookie_secure' => false,         // set true when served over HTTPS (adds the Secure cookie flag)
            'hsts' => false,                  // enable when served behind HTTPS
            'session_idle' => 900,            // seconds of inactivity before a token dies
            'session_absolute' => 28800,      // hard lifetime of a token
            'max_body_bytes' => 16384,
            'max_transfer_minor' => 1000000,  // 10,000.00 per transfer
            'transfer_rate_limit' => 10,      // transfers per user per minute
            'max_balance_minor' => 1000000000000,
            'currency' => 'VLT',              // fictional currency label
            'password_memory_cost' => 65536,  // Argon2id KiB (lowered only by tests)
            'password_time_cost' => 4,
            'login_window' => 900,
            'login_account_threshold' => 5,   // failures per account before progressive lockout
            'login_ip_threshold' => 20,       // failures per IP before progressive lockout
        ];
    }

    public static function make(string $root, array $overrides = []): self
    {
        return new self(array_replace(self::defaults($root), $overrides));
    }

    public static function fromEnv(string $root): self
    {
        $file = $root . '/.env';
        $env = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
        $get = static fn (string $k, ?string $d = null): ?string =>
            getenv($k) !== false ? (string) getenv($k) : ($env[$k] ?? $d);
        $list = static fn (?string $s): array =>
            $s === null || $s === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $s))));

        $o = [];
        if (($v = $get('APP_ENV')) !== null) $o['env'] = $v;
        if (($v = $get('DB_PATH')) !== null && $v !== '') $o['db_path'] = $v[0] === '/' ? $v : $root . '/' . $v;
        if (($v = $get('LOG_PATH')) !== null && $v !== '') $o['log_path'] = $v[0] === '/' ? $v : $root . '/' . $v;
        $o['cors_origins'] = $list($get('CORS_ALLOWED_ORIGINS'));
        $o['trusted_proxies'] = $list($get('TRUSTED_PROXIES'));
        $o['cookie_secure'] = in_array(strtolower((string) $get('COOKIE_SECURE', 'false')), ['1', 'true', 'yes'], true);
        $o['hsts'] = in_array(strtolower((string) $get('ENABLE_HSTS', 'false')), ['1', 'true', 'yes'], true);
        if (($v = $get('SESSION_IDLE_SECONDS')) !== null) $o['session_idle'] = max(60, (int) $v);
        if (($v = $get('SESSION_ABSOLUTE_SECONDS')) !== null) $o['session_absolute'] = max(300, (int) $v);
        if (($v = $get('MAX_TRANSFER_MINOR')) !== null) $o['max_transfer_minor'] = max(1, (int) $v);
        return self::make($root, $o);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key];
    }
}
