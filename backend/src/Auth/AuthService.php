<?php
declare(strict_types=1);

namespace Vault24\Auth;

use Vault24\Audit\AuditService;
use Vault24\Http\HttpException;
use Vault24\Support\Config;
use Vault24\Support\Database;

final class AuthService
{
    public function __construct(
        private Database $db,
        private Config $cfg,
        private PasswordHasher $hasher,
        private LoginThrottle $throttle,
        private AuditService $audit,
    ) {}

    /**
     * Creates a user. Role is decided by trusted server code only (never by a request field);
     * customers get an empty account — balances can only change through TransferService.
     * @return array{id:int,public_id:string,account_ref:?string}
     */
    public function createUser(string $name, string $email, string $password, string $role = 'customer'): array
    {
        $hash = $this->hasher->hash($password); // slow work happens before the write lock is taken
        return $this->db->transaction(function () use ($name, $email, $hash, $role) {
            if ($this->db->one('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
                throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', ['email' => 'This email address cannot be used.']);
            }
            $publicId = 'usr_' . bin2hex(random_bytes(12));
            $id = $this->db->insert(
                'INSERT INTO users (public_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)',
                [$publicId, $name, $email, $hash, $role]
            );
            $ref = null;
            if ($role === 'customer') {
                do {
                    $ref = '10' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT); // 10-digit account number
                } while ($this->db->one('SELECT 1 FROM accounts WHERE account_ref = ?', [$ref]) !== null);
                $this->db->run('INSERT INTO accounts (user_id, account_ref, currency, balance_minor) VALUES (?, ?, ?, 0)', [$id, $ref, $this->cfg->get('currency')]);
            }
            return ['id' => $id, 'public_id' => $publicId, 'account_ref' => $ref];
        });
    }

    /** @return array{token:string,expires_at:string,user:array} */
    public function login(string $email, string $password, string $ip, ?array $allowedRoles = null): array
    {
        try {
            $this->throttle->assertAllowed($email, $ip);
        } catch (HttpException $e) {
            $this->audit->record('login.failure', null, 'denied', null, null, ['reason' => 'throttled']);
            throw $e;
        }

        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        // Always run one password verification so timing does not reveal whether the email exists.
        $passwordOk = $this->hasher->verify($password, $user['password_hash'] ?? $this->hasher->dummyHash());

        $roleOk = $user !== null && ($allowedRoles === null || in_array($user['role'], $allowedRoles, true));
        if ($user === null || !$passwordOk || $user['status'] !== 'active' || !$roleOk) {
            $this->throttle->recordFailure($email, $ip);
            $reason = $user !== null && $passwordOk ? ($roleOk ? 'account_locked' : 'wrong_portal') : 'bad_credentials';
            $this->audit->record('login.failure', $user === null ? null : (int) $user['id'], 'failure', null, null, ['reason' => $reason]);
            throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
        }

        $this->throttle->clearAccount($email);
        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$this->hasher->hash($password), $user['id']]);
        }

        // A brand-new random token on every login (session identifiers are never reused or client-chosen).
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $this->db->run(
            'INSERT INTO sessions (user_id, token_hash, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$user['id'], hash('sha256', $token), $now, $now, $now + (int) $this->cfg->get('session_absolute')]
        );
        $this->audit->record('login.success', (int) $user['id'], 'success');

        return [
            'token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + (int) $this->cfg->get('session_absolute')),
            'user' => ['id' => $user['public_id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
        ];
    }

    /** Resolve a bearer token to a Principal, enforcing revocation, idle and absolute expiry. */
    public function authenticate(string $token): ?Principal
    {
        $row = $this->db->one(
            'SELECT s.id AS sid, s.last_seen_at, s.expires_at, s.revoked_at,
                    u.id AS uid, u.public_id, u.name, u.email, u.role, u.status
               FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            return null;
        }
        $now = time();
        if ($row['revoked_at'] !== null
            || $now >= (int) $row['expires_at']
            || $now - (int) $row['last_seen_at'] > (int) $this->cfg->get('session_idle')
            || $row['status'] !== 'active') {
            return null;
        }
        $this->db->run('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [$now, $row['sid']]);
        return new Principal((int) $row['uid'], $row['public_id'], $row['name'], $row['email'], $row['role'], (int) $row['sid']);
    }

    public function logout(Principal $p): void
    {
        $this->db->run('UPDATE sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [time(), $p->sessionId]);
        $this->audit->record('logout', $p->userId, 'success');
    }

    public function revokeAllSessions(int $userId): void
    {
        $this->db->run('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [time(), $userId]);
    }
}
