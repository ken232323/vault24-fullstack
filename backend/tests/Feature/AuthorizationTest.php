<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

test('IDOR: customer C cannot read A->B transfer; A and B and staff can; response identical to "does not exist"', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 100000); $bob = $a->user('Bob', 0); $carol = $a->user('Carol', 0); $staff = $a->user('Staff', 0, 'staff');
    $t = $a->transfer($alice, $bob['ref'], 5000);
    T::same(201, $t['status']);
    $ref = $t['json']['data']['reference'];
    T::same(200, $a->call('GET', "/api/v1/transfers/$ref", null, $alice['token'])['status']);
    T::same(200, $a->call('GET', "/api/v1/transfers/$ref", null, $bob['token'])['status']);
    T::same(200, $a->call('GET', "/api/v1/transfers/$ref", null, $staff['token'])['status']);
    $denied = $a->call('GET', "/api/v1/transfers/$ref", null, $carol['token']);
    $missing = $a->call('GET', '/api/v1/transfers/TRF-0000000000000000', null, $carol['token']);
    T::same(404, $denied['status']);
    T::same($missing['json']['error'], $denied['json']['error'], 'existence is not revealed');
    T::same(404, $a->call('GET', '/api/v1/transfers/1%20OR%201=1', null, $carol['token'])['status']);
});

test('IDOR: history and account only ever show the caller\'s own data', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 100000); $bob = $a->user('Bob', 0); $carol = $a->user('Carol', 0);
    $a->transfer($alice, $bob['ref'], 5000);
    $h = $a->call('GET', '/api/v1/transactions', null, $carol['token']);
    T::same(200, $h['status']); T::same(0, $h['json']['data']['total']);
    $ha = $a->call('GET', '/api/v1/transactions', null, $alice['token'])['json']['data'];
    T::same(1, $ha['total']); T::same('debit', $ha['items'][0]['direction']); T::same($bob['ref'], $ha['items'][0]['counterparty_account_ref']);
    $hb = $a->call('GET', '/api/v1/transactions', null, $bob['token'])['json']['data'];
    T::same('credit', $hb['items'][0]['direction']);
    T::notContains($carol['ref'], json_encode($ha));
    T::same($carol['ref'], $a->call('GET', '/api/v1/account', null, $carol['token'])['json']['data']['account_ref']);
    // account ids in query strings are ignored: authority comes from the session
    $r = $a->call('GET', '/api/v1/account?user_id=' . $alice['id'] . '&account_ref=' . $alice['ref'], null, $carol['token']);
    T::same($carol['ref'], $r['json']['data']['account_ref']);
});

test('mass assignment: profile PATCH only allows `name`; role/status/email/balance/ids are rejected and unchanged', function () {
    $a = new TestApp();
    $u = $a->user('Alice', 1000);
    T::same(200, $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Alice Renamed'], $u['token'])['status']);
    T::same('Alice Renamed', $a->db()->one('SELECT name FROM users WHERE id = ?', [$u['id']])['name']);
    foreach (['role' => 'admin', 'status' => 'locked', 'email' => 'evil@vault24.test', 'balance_minor' => 9999999, 'account_ref' => '1011111111', 'id' => 1, 'user_id' => 2, 'password_hash' => 'x'] as $k => $v) {
        $r = $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Alice', $k => $v], $u['token']);
        T::same(422, $r['status'], "field $k rejected");
    }
    T::same(422, $a->call('PATCH', '/api/v1/me/profile', [], $u['token'])['status'], 'empty body');
    $row = $a->db()->one('SELECT role, status, email FROM users WHERE id = ?', [$u['id']]);
    T::same(['role' => 'customer', 'status' => 'active', 'email' => $u['email']], $row);
    T::same(1000, $a->balance($u['id']));
    T::same(401, $a->call('PATCH', '/api/v1/me/profile', ['name' => 'x'])['status']);
});

test('role matrix: customers denied all back-office routes; staff limited; admin-only routes refuse staff; unauthenticated gets 401', function () {
    $a = new TestApp();
    $cust = $a->user('Cust', 1000); $staff = $a->user('Staff', 0, 'staff'); $admin = $a->user('Admin', 0, 'admin');
    $backoffice = ['/api/v1/admin/audit-events', '/api/v1/admin/customers', '/api/v1/admin/security-events'];
    foreach ($backoffice as $p) T::same(403, $a->call('GET', $p, null, $cust['token'])['status'], "customer $p");
    foreach ($backoffice as $p) T::same(200, $a->call('GET', $p, null, $staff['token'])['status'], "staff $p");
    foreach ([$cust, $staff] as $who) {
        T::same(403, $a->call('GET', '/api/v1/admin/staff', null, $who['token'])['status']);
        T::same(403, $a->call('POST', '/api/v1/admin/staff', ['name' => 'X', 'email' => 'x@vault24.test', 'password' => 'Correct-Horse-Battery-1', 'role' => 'admin', 'current_password' => 'x'], $who['token'])['status']);
        T::same(403, $a->call('POST', '/api/v1/admin/users/' . $cust['public_id'] . '/lock', ['reason' => 'test', 'current_password' => 'x'], $who['token'])['status']);
    }
    T::same(403, $a->call('GET', '/api/v1/account', null, $staff['token'])['status']);
    T::same(403, $a->call('GET', '/api/v1/transactions', null, $admin['token'])['status']);
    T::same(403, $a->transfer($staff, $cust['ref'], 100)['status']);
    foreach (array_merge(['/api/v1/me', '/api/v1/account', '/api/v1/transactions', '/api/v1/admin/staff', '/api/v1/admin/auth/me', '/api/v1/transfers/TRF-0000000000000000'], $backoffice) as $p) {
        T::same(401, $a->call('GET', $p)['status'], "unauthenticated $p");
    }
    foreach (array_merge($backoffice, ['/api/v1/admin/staff']) as $p) T::same(200, $a->call('GET', $p, null, $admin['token'])['status']);
    $denied = $a->db()->one("SELECT COUNT(*) AS n FROM audit_events WHERE event_type = 'authz.denied'")['n'];
    T::true((int) $denied >= 7, 'denials are audited');
});

test('staff see a limited view: no balances in customers, no metadata in audit events', function () {
    $a = new TestApp();
    $cust = $a->user('Cust', 7777); $staff = $a->user('Staff', 0, 'staff'); $admin = $a->user('Admin', 0, 'admin');
    $sc = $a->call('GET', '/api/v1/admin/customers', null, $staff['token'])['json']['data']['items'];
    T::same(null, $sc[0]['balance_minor']); T::notContains('7777', json_encode($sc));
    $ac = $a->call('GET', '/api/v1/admin/customers', null, $admin['token'])['json']['data']['items'];
    T::same(7777, $ac[0]['balance_minor']);
    $se = $a->call('GET', '/api/v1/admin/audit-events', null, $staff['token'])['json']['data']['items'];
    T::true(count($se) > 0); foreach ($se as $e) T::true(!array_key_exists('meta', $e), 'no meta for staff');
    $ae = $a->call('GET', '/api/v1/admin/audit-events', null, $admin['token'])['json']['data']['items'];
    T::true(array_key_exists('meta', $ae[0]), 'meta for admin');
    T::true(isset($ae[0]['actor_name']) || $ae[0]['actor_user_id'] === null);
});

test('admin audit access is itself audited and filterable; params are validated', function () {
    $a = new TestApp();
    $admin = $a->user('Admin', 0, 'admin');
    $r = $a->call('GET', '/api/v1/admin/audit-events?event_type=login.success&per_page=5', null, $admin['token']);
    T::same(200, $r['status']);
    foreach ($r['json']['data']['items'] as $i) T::same('login.success', $i['event_type']);
    T::same(1, (int) $a->db()->one("SELECT COUNT(*) AS n FROM audit_events WHERE event_type='audit.accessed'")['n']);
    T::same(422, $a->call('GET', "/api/v1/admin/audit-events?event_type=x'%20OR%20'1'='1", null, $admin['token'])['status']);
    T::same(422, $a->call('GET', '/api/v1/admin/audit-events?outcome=bogus', null, $admin['token'])['status']);
    T::same(422, $a->call('GET', '/api/v1/admin/audit-events?per_page=101', null, $admin['token'])['status']);
});

test('admin lock/unlock: needs reason + re-authentication, revokes sessions, blocks login, is audited', function () {
    $a = new TestApp();
    $admin = $a->user('Admin', 0, 'admin'); $cust = $a->user('Cust', 0);
    $url = '/api/v1/admin/users/' . $cust['public_id'] . '/lock';
    T::same(422, $a->call('POST', $url, ['current_password' => $admin['password']], $admin['token'])['status'], 'reason required');
    T::same(403, $a->call('POST', $url, ['reason' => 'suspicious', 'current_password' => 'Wrong-Password-123'], $admin['token'])['status'], 're-auth required');
    T::same('active', $a->db()->one('SELECT status FROM users WHERE id = ?', [$cust['id']])['status']);
    T::same(422, $a->call('POST', '/api/v1/admin/users/' . $admin['public_id'] . '/lock', ['reason' => 'self', 'current_password' => $admin['password']], $admin['token'])['status'], 'cannot lock self');
    T::same(404, $a->call('POST', '/api/v1/admin/users/usr_nope/lock', ['reason' => 'abc', 'current_password' => $admin['password']], $admin['token'])['status']);
    T::same(200, $a->call('POST', $url, ['reason' => 'suspicious activity', 'current_password' => $admin['password']], $admin['token'])['status']);
    T::same(401, $a->call('GET', '/api/v1/me', null, $cust['token'])['status'], 'existing sessions revoked');
    T::same(401, $a->call('POST', '/api/v1/auth/login', ['email' => $cust['email'], 'password' => $cust['password']])['status'], 'locked user cannot log in');
    T::same(200, $a->call('POST', '/api/v1/admin/users/' . $cust['public_id'] . '/unlock', ['reason' => 'verified by phone', 'current_password' => $admin['password']], $admin['token'])['status']);
    T::same(200, $a->call('POST', '/api/v1/auth/login', ['email' => $cust['email'], 'password' => $cust['password']], null, [], null, '198.51.100.200')['status']);
    $ev = $a->db()->all("SELECT outcome, meta FROM audit_events WHERE event_type = 'admin.action' ORDER BY id");
    T::same(4, count($ev), 'reauth-denied, self-target-denied, lock, unlock');
    T::same(['denied', 'denied', 'success', 'success'], array_column($ev, 'outcome'));
    T::contains('suspicious activity', $ev[2]['meta']);
    T::notContains($admin['password'], json_encode($ev), 're-auth password never audited');
});
