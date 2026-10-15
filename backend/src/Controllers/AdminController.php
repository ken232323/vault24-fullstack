<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Audit\AuditService;
use Vault24\Auth\AuthService;
use Vault24\Auth\PasswordHasher;
use Vault24\Auth\Principal;
use Vault24\Http\HttpException;
use Vault24\Http\Request;
use Vault24\Http\Response;
use Vault24\Http\Validator;
use Vault24\Support\Database;

final class AdminController
{
    public function __construct(private Database $db, private AuditService $audit, private AuthService $auth, private PasswordHasher $hasher) {}

    public function auditEvents(Request $r, Principal $p): Response
    {
        [$page, $perPage] = Validator::pagination($r->query, 100);
        $filters = [];
        if (isset($r->query['event_type'])) {
            if (!is_string($r->query['event_type']) || !preg_match('/^[a-z]{1,20}\.?[a-z_]{0,20}$/', $r->query['event_type'])) {
                throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['event_type' => 'Invalid event type.']);
            }
            $filters['event_type'] = $r->query['event_type'];
        }
        if (isset($r->query['outcome'])) {
            if (!in_array($r->query['outcome'], ['success', 'failure', 'denied'], true)) {
                throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['outcome' => 'Invalid outcome.']);
            }
            $filters['outcome'] = $r->query['outcome'];
        }
        if (isset($r->query['actor_user_id'])) {
            if (!is_string($r->query['actor_user_id']) || !preg_match('/^\d{1,9}$/', $r->query['actor_user_id'])) {
                throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['actor_user_id' => 'Invalid actor id.']);
            }
            $filters['actor_user_id'] = $r->query['actor_user_id'];
        }
        // Admins see full metadata; staff get a limited view without it.
        $res = $this->audit->search($filters, $page, $perPage, $p->role === 'admin');
        // Reading the audit log is itself audited.
        $this->audit->record('audit.accessed', $p->userId, 'success', 'audit', null, ['scope' => json_encode($filters, JSON_THROW_ON_ERROR), 'result_count' => count($res['items'])]);
        return Response::data(['items' => $res['items'], 'page' => $page, 'per_page' => $perPage, 'total' => $res['total']]);
    }

    public function setLock(Request $r, Principal $p, array $params, bool $lock): Response
    {
        $action = $lock ? 'user.lock' : 'user.unlock';
        $targetId = $params['id'] ?? '';
        $v = (new Validator($r->json()))->onlyKeys(['reason', 'current_password']);
        $reason = $v->string('reason', 3, 200);
        $password = $v->string('current_password', 1, 256, false);
        $v->fail();

        // Re-authentication for a high-risk action.
        $me = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$p->userId]);
        if (!$this->hasher->verify($password, $me['password_hash'])) {
            $this->audit->record('admin.action', $p->userId, 'denied', 'user', $targetId, ['action' => $action, 'reason' => $reason, 'result' => 'reauthentication_failed']);
            throw new HttpException(403, 'reauthentication_failed', 'Re-authentication failed.');
        }
        $target = preg_match('/^usr_[a-f0-9]{24}$/', $targetId) ? $this->db->one('SELECT id, status FROM users WHERE public_id = ?', [$targetId]) : null;
        if ($target === null) {
            throw new HttpException(404, 'not_found', 'Resource not found.');
        }
        if ((int) $target['id'] === $p->userId) {
            $this->audit->record('admin.action', $p->userId, 'denied', 'user', $targetId, ['action' => $action, 'reason' => $reason, 'result' => 'self_target']);
            throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['id' => 'You cannot change your own status.']);
        }
        $status = $lock ? 'locked' : 'active';
        $this->db->run('UPDATE users SET status = ? WHERE id = ?', [$status, $target['id']]);
        if ($lock) {
            $this->auth->revokeAllSessions((int) $target['id']);
        }
        $this->audit->record('admin.action', $p->userId, 'success', 'user', $targetId, ['action' => $action, 'reason' => $reason, 'result' => $status]);
        return Response::data(['id' => $targetId, 'status' => $status]);
    }

    /** Customers list. Balances are admin-only (staff receive null). Access is audited. */
    public function customers(Request $r, Principal $p): Response
    {
        [$page, $perPage] = Validator::pagination($r->query, 100);
        $total = (int) $this->db->one("SELECT COUNT(*) AS n FROM users WHERE role = 'customer'")['n'];
        $rows = $this->db->all(
            "SELECT u.public_id AS id, u.name, u.email, u.status, u.created_at, a.account_ref, a.balance_minor
               FROM users u LEFT JOIN accounts a ON a.user_id = u.id
              WHERE u.role = 'customer' ORDER BY u.id DESC LIMIT ? OFFSET ?",
            [$perPage, ($page - 1) * $perPage]
        );
        foreach ($rows as &$row) {
            $row['balance_minor'] = $p->role === 'admin' && $row['balance_minor'] !== null ? (int) $row['balance_minor'] : null;
        }
        $this->audit->record('audit.accessed', $p->userId, 'success', 'customer', null, ['scope' => 'customers', 'result_count' => count($rows)]);
        return Response::data(['items' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    /** Failed logins, throttling, denied access and failed transfers, with a server-computed severity. */
    public function securityEvents(Request $r, Principal $p): Response
    {
        $res = $this->audit->search([], 1, 200, true, ['failure', 'denied']);
        $events = [];
        foreach ($res['items'] as $e) {
            $meta = is_array($e['meta']) ? $e['meta'] : [];
            $reason = (string) ($meta['reason'] ?? $meta['result'] ?? ($meta['route'] ?? ''));
            $severity = ($e['outcome'] === 'denied' || $reason === 'throttled') ? 'high'
                : (str_starts_with($e['event_type'], 'transfer') ? 'medium' : 'low');
            $events[] = [
                'id' => (string) $e['id'], 'severity' => $severity, 'event_type' => $e['event_type'], 'outcome' => $e['outcome'],
                'actor_name' => $e['actor_name'], 'detail' => $reason, 'created_at' => $e['created_at'], 'request_id' => $e['request_id'],
            ];
        }
        return Response::data(['items' => $events]);
    }

    public function staffList(Request $r, Principal $p): Response
    {
        $rows = $this->db->all("SELECT public_id AS id, name, email, role, created_at FROM users WHERE role IN ('staff','admin') ORDER BY id");
        return Response::data(['items' => $rows]);
    }

    /** Create a staff/admin account. High-risk, so it needs the acting admin's current password. */
    public function createStaff(Request $r, Principal $p): Response
    {
        $v = (new Validator($r->json()))->onlyKeys(['name', 'email', 'password', 'role', 'current_password']);
        $name = $v->string('name', 1, 100);
        $email = $v->email('email');
        $password = $v->password('password', $this->hasher->maxBytes, $email);
        $role = $v->oneOf('role', ['staff', 'admin']);
        $current = $v->string('current_password', 1, 256, false);
        $v->fail();

        $me = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$p->userId]);
        if (!$this->hasher->verify($current, $me['password_hash'])) {
            $this->audit->record('admin.action', $p->userId, 'denied', 'user', null, ['action' => 'staff.create', 'reason' => 'create ' . $role, 'result' => 'reauthentication_failed']);
            throw new HttpException(403, 'reauthentication_failed', 'Re-authentication failed.');
        }
        $u = $this->auth->createUser($name, $email, $password, $role); // duplicate email -> 422 details.email
        $this->audit->record('admin.action', $p->userId, 'success', 'user', $u['public_id'], ['action' => 'staff.create', 'reason' => 'create ' . $role, 'result' => 'created']);
        return Response::data(['id' => $u['public_id'], 'name' => $name, 'email' => $email, 'role' => $role, 'created_at' => gmdate('Y-m-d\TH:i:s\Z')], 201);
    }
}
