<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

const GOOD_PW = 'Correct-Horse-Battery-99';

test('registration: invalid email, short/common password are rejected with field errors', function () {
    $a = new TestApp();
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'X', 'email' => 'not-an-email', 'password' => GOOD_PW]);
    T::same(422, $r['status']); T::true(isset($r['json']['error']['details']['email']));
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'X', 'email' => 'x@vault24.test', 'password' => 'short-pw-11']);
    T::same(422, $r['status']); T::true(isset($r['json']['error']['details']['password']));
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'X', 'email' => 'x@vault24.test', 'password' => 'passwordpassword']);
    T::same(422, $r['status'], 'common password blocked');
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'X', 'email' => 'x@vault24.test', 'password' => 'aaaaaaaaaaaaaaaa']);
    T::same(422, $r['status'], 'low-entropy password blocked');
    T::same(0, $a->count('users'));
});

test('registration: role=admin / status / balance fields cannot be supplied (role stays customer)', function () {
    $a = new TestApp();
    foreach (['role' => 'admin', 'status' => 'active', 'balance_minor' => 999999, 'account_ref' => '1000000001', 'id' => 1] as $k => $v) {
        $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'Mallory', 'email' => 'm@vault24.test', 'password' => GOOD_PW, $k => $v]);
        T::same(422, $r['status'], "field $k rejected");
        T::same('Unexpected field.', $r['json']['error']['details'][$k] ?? null);
    }
    T::same(0, $a->count('users'));
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'Mallory', 'email' => '  M@Vault24.TEST ', 'password' => GOOD_PW]);
    T::same(201, $r['status']);
    T::same('customer', $r['json']['data']['user']['role']);
    T::same('m@vault24.test', $r['json']['data']['user']['email'], 'email normalised');
    T::same(0, (int) $a->db()->one('SELECT balance_minor AS b FROM accounts')['b'], 'initial balance is always 0');
    T::notContains('password_hash', $r['raw']); T::notContains('$argon2', $r['raw']); T::notContains('$2y$', $r['raw']);
});

test('registration: duplicate email is refused and no second user is created', function () {
    $a = new TestApp();
    $body = ['name' => 'Dup', 'email' => 'dup@vault24.test', 'password' => GOOD_PW];
    T::same(201, $a->call('POST', '/api/v1/auth/register', $body)['status']);
    $r = $a->call('POST', '/api/v1/auth/register', $body);
    T::same(422, $r['status']);
    T::same(1, $a->count('users'));
});

test('passwords are stored with Argon2id/bcrypt, salted, never plaintext', function () {
    $a = new TestApp();
    $u1 = $a->user('One'); $u2 = $a->user('Two');
    $h1 = $a->db()->one('SELECT password_hash FROM users WHERE id = ?', [$u1['id']])['password_hash'];
    $h2 = $a->db()->one('SELECT password_hash FROM users WHERE id = ?', [$u2['id']])['password_hash'];
    T::true(str_starts_with($h1, '$argon2id$') || str_starts_with($h1, '$2y$'), 'modern hash');
    T::notContains($u1['password'], $h1);
    T::true($h1 !== $h2);
});

test('login: wrong password and unknown email give the identical generic response', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    $bad = $a->call('POST', '/api/v1/auth/login', ['email' => $u['email'], 'password' => 'Wrong-Password-123']);
    $unk = $a->call('POST', '/api/v1/auth/login', ['email' => 'ghost@vault24.test', 'password' => 'Wrong-Password-123']);
    T::same(401, $bad['status']); T::same(401, $unk['status']);
    T::same($bad['json']['error'], $unk['json']['error'], 'no account enumeration');
});

test('login: repeated failures are throttled (429 + Retry-After) even with the right password, from any IP', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    for ($i = 0; $i < 5; $i++) {
        T::same(401, $a->call('POST', '/api/v1/auth/login', ['email' => $u['email'], 'password' => 'Wrong-Password-123'], null, [], null, '192.0.2.10')['status']);
    }
    $r = $a->call('POST', '/api/v1/auth/login', ['email' => $u['email'], 'password' => $u['password']], null, [], null, '192.0.2.10');
    T::same(429, $r['status']); T::true(isset($r['headers']['Retry-After']));
    $r = $a->call('POST', '/api/v1/auth/login', ['email' => $u['email'], 'password' => $u['password']], null, [], null, '192.0.2.99');
    T::same(429, $r['status'], 'per-account lockout applies across IPs');
});

test('login: per-IP throttle protects against credential stuffing across many accounts', function () {
    $a = new TestApp();
    $hit = false;
    for ($i = 0; $i < 30; $i++) {
        $r = $a->call('POST', '/api/v1/auth/login', ['email' => "victim$i@vault24.test", 'password' => 'Wrong-Password-123'], null, [], null, '192.0.2.77');
        if ($r['status'] === 429) { $hit = true; break; }
    }
    T::true($hit, 'IP got throttled');
});

test('logout revokes the token server-side; /me then returns 401', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    T::same(200, $a->call('GET', '/api/v1/me', null, $u['token'])['status']);
    T::same(200, $a->call('POST', '/api/v1/auth/logout', null, $u['token'])['status']);
    T::same(401, $a->call('GET', '/api/v1/me', null, $u['token'])['status']);
});

test('sessions: tokens are stored hashed, expire when idle, and expire absolutely', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    T::same(null, $a->db()->one('SELECT 1 FROM sessions WHERE token_hash = ?', [$u['token']]), 'raw token not stored');
    T::true($a->db()->one('SELECT 1 FROM sessions WHERE token_hash = ?', [hash('sha256', $u['token'])]) !== null);
    $a->db()->run('UPDATE sessions SET last_seen_at = ?', [time() - 5000]);
    T::same(401, $a->call('GET', '/api/v1/me', null, $u['token'])['status'], 'idle timeout');
    $u2 = $a->user('Bob');
    $a->db()->run('UPDATE sessions SET expires_at = ? WHERE user_id = ?', [time() - 1, $u2['id']]);
    T::same(401, $a->call('GET', '/api/v1/me', null, $u2['token'])['status'], 'absolute expiry');
});

test('every login issues a fresh token; garbage/missing tokens get 401', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    $r = $a->call('POST', '/api/v1/auth/login', ['email' => $u['email'], 'password' => $u['password']]);
    T::true($r['json']['data']['token'] !== $u['token']);
    T::same(401, $a->call('GET', '/api/v1/me', null, 'A_totally_made_up_token_value_0000000000')['status']);
    T::same(401, $a->call('GET', '/api/v1/me')['status']);
    T::same(401, $a->call('GET', '/api/v1/me', null, null, ['Authorization' => 'Basic abc'])['status']);
});
