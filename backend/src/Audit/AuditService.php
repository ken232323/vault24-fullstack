<?php
declare(strict_types=1);

namespace Vault24\Audit;

use Vault24\Support\Database;
use Vault24\Support\Logger;

/** Append-only audit interface (writes + filtered reads). Failures never break the business flow. */
final class AuditService
{
    private ?string $requestId = null;

    public function __construct(private Database $db, private Logger $log) {}

    public function setRequestId(?string $id): void
    {
        $this->requestId = $id;
    }

    public function record(string $event, ?int $actorId, string $outcome, ?string $targetType = null, ?string $targetId = null, array $meta = []): void
    {
        if (!AuditRedactor::isKnown($event)) {
            throw new \InvalidArgumentException("Unknown audit event type: $event");
        }
        try {
            $this->db->run(
                'INSERT INTO audit_events (actor_user_id, event_type, target_type, target_id, outcome, request_id, meta) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$actorId, $event, $targetType, $targetId, $outcome, $this->requestId, json_encode(AuditRedactor::clean($event, $meta) ?: new \stdClass(), JSON_THROW_ON_ERROR)]
            );
        } catch (\Throwable $e) {
            // Logging failure must not corrupt or abort the financial flow, and must not leak data.
            $this->log->error($this->requestId ?? '-', $e);
        }
    }

    /** @return array{items:list<array>,total:int} */
    public function search(array $filters, int $page, int $perPage, bool $includeMeta = true, array $outcomes = []): array
    {
        $where = [];
        $params = [];
        foreach (['event_type', 'outcome'] as $f) {
            if (isset($filters[$f])) {
                $where[] = "e.$f = ?";
                $params[] = $filters[$f];
            }
        }
        if (isset($filters['actor_user_id'])) {
            $where[] = 'e.actor_user_id = ?';
            $params[] = (int) $filters['actor_user_id'];
        }
        if ($outcomes !== []) {
            $where[] = 'e.outcome IN (' . implode(',', array_fill(0, count($outcomes), '?')) . ')';
            array_push($params, ...$outcomes);
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) ($this->db->one("SELECT COUNT(*) AS n FROM audit_events e $w", $params)['n'] ?? 0);
        $rows = $this->db->all(
            "SELECT e.id, e.actor_user_id, u.name AS actor_name, e.event_type, e.target_type, e.target_id, e.outcome, e.request_id, e.meta, e.created_at
               FROM audit_events e LEFT JOIN users u ON u.id = e.actor_user_id $w ORDER BY e.id DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );
        foreach ($rows as &$r) {
            if ($includeMeta) {
                $r['meta'] = json_decode($r['meta'], true) ?: new \stdClass();
            } else {
                unset($r['meta']); // limited (staff) view: no metadata
            }
        }
        return ['items' => $rows, 'total' => $total];
    }
}
