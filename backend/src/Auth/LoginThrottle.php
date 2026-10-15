<?php
declare(strict_types=1);

namespace Vault24\Auth;

use Vault24\Http\HttpException;
use Vault24\Support\Config;
use Vault24\Support\Database;

/**
 * Per-account and per-IP failed-login throttling with progressive lockout:
 * after N failures inside the window, lock for 30s, 60s, 120s ... capped at the window length.
 * Attempts made while locked are not recorded, so an attacker cannot extend a victim's lock forever.
 */
final class LoginThrottle
{
    public function __construct(private Database $db, private Config $cfg) {}

    private function buckets(string $email, string $ip): array
    {
        return [
            ['acct:' . hash('sha256', $email), (int) $this->cfg->get('login_account_threshold')],
            ['ip:' . $ip, (int) $this->cfg->get('login_ip_threshold')],
        ];
    }

    public function assertAllowed(string $email, string $ip): void
    {
        $now = time();
        $window = (int) $this->cfg->get('login_window');
        foreach ($this->buckets($email, $ip) as [$bucket, $threshold]) {
            $row = $this->db->one('SELECT COUNT(*) AS n, MAX(created_at) AS last FROM login_attempts WHERE bucket = ? AND created_at > ?', [$bucket, $now - $window]);
            $n = (int) ($row['n'] ?? 0);
            if ($n >= $threshold) {
                $lock = min($window, 30 * (2 ** min($n - $threshold, 5)));
                $wait = (int) $row['last'] + $lock - $now;
                if ($wait > 0) {
                    throw new HttpException(429, 'too_many_attempts', 'Too many attempts. Please try again later.', [], ['Retry-After' => (string) $wait]);
                }
            }
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        $now = time();
        foreach ($this->buckets($email, $ip) as [$bucket]) {
            $this->db->run('INSERT INTO login_attempts (bucket, created_at) VALUES (?, ?)', [$bucket, $now]);
        }
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM login_attempts WHERE created_at < ?', [$now - 2 * (int) $this->cfg->get('login_window')]);
        }
    }

    public function clearAccount(string $email): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE bucket = ?', ['acct:' . hash('sha256', $email)]);
    }
}
