<?php
declare(strict_types=1);

namespace Vault24\Audit;

/** Central redaction: per-event field allowlist PLUS a deny-pattern pass over keys and values. */
final class AuditRedactor
{
    /** event type => metadata keys that may be stored */
    public const ALLOWED = [
        'register.success' => [],
        'login.success' => [],
        'login.failure' => ['reason'],
        'logout' => [],
        'transfer.created' => ['reference', 'source_account_id', 'destination_account_id', 'amount_minor', 'currency'],
        'transfer.failed' => ['reason', 'amount_minor', 'currency'],
        'profile.updated' => ['changed_fields'],
        'admin.action' => ['action', 'reason', 'result'],
        'audit.accessed' => ['scope', 'result_count'],
        'authz.denied' => ['route', 'required_roles'],
    ];

    private const FORBIDDEN_KEY = '/pass|secret|token|authorization|cookie|hash|api[_-]?key|bearer/i';

    public static function isKnown(string $event): bool
    {
        return isset(self::ALLOWED[$event]);
    }

    public static function clean(string $event, array $meta): array
    {
        $allowed = self::ALLOWED[$event] ?? [];
        $out = [];
        foreach ($meta as $k => $v) {
            if (!in_array($k, $allowed, true) || preg_match(self::FORBIDDEN_KEY, (string) $k)) {
                continue;
            }
            $out[$k] = self::value($v);
        }
        return $out;
    }

    private static function value(mixed $v): mixed
    {
        if (is_array($v)) {
            $r = [];
            foreach ($v as $k => $item) {
                if (!preg_match(self::FORBIDDEN_KEY, (string) $k)) {
                    $r[$k] = self::value($item);
                }
            }
            return $r;
        }
        if (is_string($v)) {
            if (preg_match('/bearer\s+\S+/i', $v)) {
                return '[redacted]';
            }
            return mb_substr($v, 0, 200, 'UTF-8');
        }
        return is_scalar($v) || $v === null ? $v : '[unsupported]';
    }
}
