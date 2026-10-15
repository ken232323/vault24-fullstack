<?php
declare(strict_types=1);

namespace Vault24\Support;

/** Fixed-window counter stored in the database. hit() returns 0 if allowed, else seconds to wait. */
final class RateLimiter
{
    public function __construct(private Database $db) {}

    public function hit(string $bucket, int $limit, int $window): int
    {
        $now = time();
        $start = $now - ($now % $window);
        $this->db->run(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1',
            [$bucket, $start]
        );
        $row = $this->db->one('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $start]);
        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }
        return ((int) ($row['hits'] ?? 0)) > $limit ? max(1, $start + $window - $now) : 0;
    }
}
