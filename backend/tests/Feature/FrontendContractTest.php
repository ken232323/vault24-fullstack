<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

/** Everything the React apps depend on: cookie sessions, CSRF, admin portal, note, history fields. */
const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

function cookie_from(array $res): string
{
    preg_match('/^([A-Za-z0-9_]+=[A-Za-z0-9_\-]+);/', $res['headers']['Set-Cookie'] ?? '', $m);
    return $m[1] ?? '';
}

test('browser login: token only in an HttpOnly SameSite=Strict cookie, never in the body; API clients still get a body token', function () {
    $a = new TestApp();
    $u = $a->k->auth->createUser('Alice', 'alice@vault24.test', 'Correct-Horse-Battery-1');
    $r = $a->call('POST', '/api/v1/auth/login', ['email' => 'alice@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR);
    T::same(200, $r['status']);
    T::contains('v24_session=', $r['headers']['Set-Cookie']);
    foreach (['HttpOnly', 'SameSite=Strict', 'Path=/'] as $flag) T::contains($flag, $r['headers']['Set-Cookie']);
    T::notContains('token', $r['raw'], 'no token in browser-mode body');
    T::same('alice@vault24.test', $r['json']['data']['user']['email'], 'user carries email for the UI');
    $api = $a->call('POST', '/api/v1/auth/login', ['email' => 'alice@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, [], null, '198.51.100.9');
    T::true(isset($api['json']['data']['token']) && !isset($api['headers']['Set-Cookie']));
    $a2 = new TestApp(['cookie_secure' => true]);
    $a2->k->auth->createUser('B', 'b@vault24.test', 'Correct-Horse-Battery-1');
    T::contains('Secure', $a2->call('POST', '/api/v1/auth/login', ['email' => 'b@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR)['headers']['Set-Cookie']);
});

test('cookie session works for reads and writes; logout clears the cookie and revokes server-side', function () {
    $a = new TestApp();
    $a->k->auth->createUser('Alice', 'alice@vault24.test', 'Correct-Horse-Battery-1');
    $ck = cookie_from($a->call('POST', '/api/v1/auth/login', ['email' => 'alice@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR));
    $h = XHR + ['Cookie' => $ck];
    T::same(200, $a->call('GET', '/api/v1/me', null, null, $h)['status']);
    T::same(200, $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Alice Z'], null, $h)['status']);
    $out = $a->call('POST', '/api/v1/auth/logout', null, null, $h);
    T::same(200, $out['status']); T::contains('Max-Age=0', $out['headers']['Set-Cookie']);
    T::same(401, $a->call('GET', '/api/v1/me', null, null, $h)['status'], 'old cookie is dead server-side');
});

test('CSRF: cookie-authenticated writes need X-Requested-With and a trusted Origin; reads are unaffected', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 5000); $bob = $a->user('Bob', 0);
    $a->k->auth->createUser('Cookie', 'cookie@vault24.test', 'Correct-Horse-Battery-1');
    $ck = cookie_from($a->call('POST', '/api/v1/auth/login', ['email' => 'cookie@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR));
    // forged cross-site style request: cookie but no custom header
    T::same(403, $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Pwned'], null, ['Cookie' => $ck])['status']);
    T::same('csrf_rejected', $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Pwned'], null, ['Cookie' => $ck])['json']['error']['code']);
    // header present but hostile Origin
    T::same(403, $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Pwned'], null, XHR + ['Cookie' => $ck, 'Origin' => 'https://evil.example'])['status']);
    // allow-listed Origin (the Vite dev origin in real life) is fine
    $b = new TestApp(['cors_origins' => ['http://localhost:5173']]);
    $b->k->auth->createUser('Cookie', 'cookie@vault24.test', 'Correct-Horse-Battery-1');
    $ck2 = cookie_from($b->call('POST', '/api/v1/auth/login', ['email' => 'cookie@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR));
    T::same(200, $b->call('PATCH', '/api/v1/me/profile', ['name' => 'Fine'], null, XHR + ['Cookie' => $ck2, 'Origin' => 'http://localhost:5173'])['status']);
    T::same(200, $a->call('GET', '/api/v1/me', null, null, ['Cookie' => $ck])['status'], 'GET needs no header');
    $row = $a->db()->one('SELECT name FROM users WHERE email = ?', ['cookie@vault24.test']);
    T::same('Cookie', $row['name'], 'forged writes changed nothing');
});

test('customer cookie is useless on admin routes and vice-versa; admin portal rejects customers', function () {
    $a = new TestApp();
    $a->k->auth->createUser('Cust', 'cust@vault24.test', 'Correct-Horse-Battery-1');
    $a->k->auth->createUser('Ada', 'ada@vault24.test', 'Correct-Horse-Battery-2', 'admin');
    $custCk = cookie_from($a->call('POST', '/api/v1/auth/login', ['email' => 'cust@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR));
    // customers cannot sign in through the admin portal (generic failure, no cookie)
    $bad = $a->call('POST', '/api/v1/admin/auth/login', ['email' => 'cust@vault24.test', 'password' => 'Correct-Horse-Battery-1'], null, XHR);
    T::same(401, $bad['status']); T::true(!isset($bad['headers']['Set-Cookie'])); T::same('invalid_credentials', $bad['json']['error']['code']);
    // the customer cookie value presented under the admin cookie name is still just a customer session -> 403
    $stolen = 'v24_admin_session=' . explode('=', $custCk, 2)[1];
    T::same(403, $a->call('GET', '/api/v1/admin/customers', null, null, ['Cookie' => $stolen])['status']);
    T::same(401, $a->call('GET', '/api/v1/admin/customers', null, null, ['Cookie' => $custCk])['status'], 'wrong cookie name is ignored on /admin');
    $adm = $a->call('POST', '/api/v1/admin/auth/login', ['email' => 'ada@vault24.test', 'password' => 'Correct-Horse-Battery-2'], null, XHR);
    T::same(200, $adm['status']); T::contains('v24_admin_session=', $adm['headers']['Set-Cookie']); T::same('admin', $adm['json']['data']['user']['role']);
    $adminCk = cookie_from($adm);
    T::same(200, $a->call('GET', '/api/v1/admin/auth/me', null, null, ['Cookie' => $adminCk])['status']);
    T::same(401, $a->call('GET', '/api/v1/me', null, null, ['Cookie' => $adminCk])['status'], 'admin cookie is not read on customer routes');
    T::same(200, $a->call('POST', '/api/v1/admin/auth/logout', null, null, XHR + ['Cookie' => $adminCk])['status']);
    T::same(401, $a->call('GET', '/api/v1/admin/auth/me', null, null, ['Cookie' => $adminCk])['status']);
});

test('accounts are 10-digit numbers assigned by the server; registration cannot choose one', function () {
    $a = new TestApp();
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => 'N', 'email' => 'n@vault24.test', 'password' => 'Correct-Horse-Battery-1']);
    T::same(1, preg_match('/^10\d{8}$/', $r['json']['data']['account_ref']), 'account number format');
    $r2 = $a->call('POST', '/api/v1/auth/register', ['name' => 'N', 'email' => 'n2@vault24.test', 'password' => 'Correct-Horse-Battery-1', 'accountNumber' => '1001000003']);
    T::same(422, $r2['status']);
});

test('transfer: optional note stored, returned in history with counterparty name/status; response carries the committed balance', function () {
    $a = new TestApp();
    $alice = $a->user('Alice Banda', 10000); $bob = $a->user('Bob Phiri', 0);
    $r = $a->transfer($alice, $bob['ref'], 2500, 'note-key-0001', ['note' => 'Lunch money']);
    T::same(201, $r['status']); T::same('Lunch money', $r['json']['data']['note']); T::same(7500, $r['json']['data']['source_balance_minor']);
    $h = $a->call('GET', '/api/v1/transactions', null, $alice['token'])['json']['data']['items'][0];
    T::same(['debit', 'completed', 'Lunch money', 'Bob Phiri'], [$h['direction'], $h['status'], $h['note'], $h['counterparty_name']]);
    $hb = $a->call('GET', '/api/v1/transactions', null, $bob['token'])['json']['data']['items'][0];
    T::same(['credit', 'Alice Banda'], [$hb['direction'], $hb['counterparty_name']]);
    T::same(422, $a->transfer($alice, $bob['ref'], 100, null, ['note' => str_repeat('n', 61)])['status'], 'note max 60');
    T::same(422, $a->transfer($alice, $bob['ref'], 100, null, ['note' => ['x']])['status']);
    // a different note is a different request payload for the same idempotency key
    T::same(409, $a->transfer($alice, $bob['ref'], 2500, 'note-key-0001', ['note' => 'Other'])['status']);
    $again = $a->transfer($alice, $bob['ref'], 2500, 'note-key-0001', ['note' => 'Lunch money']);
    T::same([200, 7500], [$again['status'], $again['json']['data']['source_balance_minor']]);
    T::same('', $a->transfer($alice, $bob['ref'], 100)['json']['data']['note'], 'note defaults to empty');
});

test('admin: security events are derived server-side with severity; staff list/create need admin + re-authentication', function () {
    $a = new TestApp();
    $admin = $a->user('Ada', 0, 'admin'); $staff = $a->user('Sam', 0, 'staff'); $cust = $a->user('Cust', 100); $bob = $a->user('Bob');
    $a->call('POST', '/api/v1/auth/login', ['email' => $cust['email'], 'password' => 'Wrong-Password-1']);
    $a->call('GET', '/api/v1/admin/customers', null, $cust['token']);       // denied
    $a->transfer($cust, $bob['ref'], 99999);                                 // insufficient funds
    $ev = $a->call('GET', '/api/v1/admin/security-events', null, $staff['token'])['json']['data']['items'];
    $by = array_column($ev, 'severity', 'event_type');
    T::same('high', $by['authz.denied']); T::same('medium', $by['transfer.failed']); T::same('low', $by['login.failure']);
    foreach ($ev as $e) T::true(in_array($e['outcome'], ['failure', 'denied'], true));
    // staff create
    $body = ['name' => 'New Staff', 'email' => 'new@vault24.test', 'password' => 'Another-Strong-Pass-1', 'role' => 'staff'];
    T::same(403, $a->call('POST', '/api/v1/admin/staff', $body + ['current_password' => 'Wrong-Password-1'], $admin['token'])['status'], 're-auth required');
    T::same(422, $a->call('POST', '/api/v1/admin/staff', $body, $admin['token'])['status'], 'current_password missing');
    T::same(422, $a->call('POST', '/api/v1/admin/staff', ['role' => 'customer'] + $body + ['current_password' => $admin['password']], $admin['token'])['status'], 'role enum');
    $ok = $a->call('POST', '/api/v1/admin/staff', $body + ['current_password' => $admin['password']], $admin['token']);
    T::same(201, $ok['status']); T::same('staff', $ok['json']['data']['role']);
    T::same('staff', $a->db()->one('SELECT role FROM users WHERE email = ?', ['new@vault24.test'])['role']);
    T::same(0, $a->count('accounts') - 2, 'staff accounts get no bank account (only the 2 customers do)');
    T::same(422, $a->call('POST', '/api/v1/admin/staff', $body + ['current_password' => $admin['password']], $admin['token'])['status'], 'duplicate email');
    $names = array_column($a->call('GET', '/api/v1/admin/staff', null, $admin['token'])['json']['data']['items'], 'role');
    T::same(3, count($names));
    T::notContains($admin['password'], json_encode($a->db()->all('SELECT meta FROM audit_events')));
});

test('CORS: credentialed requests only for allow-listed origins; X-Requested-With is an allowed header', function () {
    $a = new TestApp(['cors_origins' => ['http://localhost:5173']]);
    $ok = $a->call('OPTIONS', '/api/v1/transfers', null, null, ['Origin' => 'http://localhost:5173']);
    T::contains('X-Requested-With', $ok['headers']['Access-Control-Allow-Headers']);
    T::same('true', $ok['headers']['Access-Control-Allow-Credentials']);
    T::same('http://localhost:5173', $ok['headers']['Access-Control-Allow-Origin']);
    $bad = $a->call('GET', '/health', null, null, ['Origin' => 'http://evil.example']);
    T::true(!isset($bad['headers']['Access-Control-Allow-Credentials']) && !isset($bad['headers']['Access-Control-Allow-Origin']));
});

test('database upgrade: migrate() adds transfers.note to a pre-existing database without data loss', function () {
    $f = sys_get_temp_dir() . '/v24old_' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $f);
    $pdo->exec("CREATE TABLE transfers (id INTEGER PRIMARY KEY, reference TEXT, source_account_id INT, destination_account_id INT, amount_minor INT, currency TEXT, status TEXT, created_at TEXT)");
    $pdo->exec("INSERT INTO transfers VALUES (1,'TRF-OLD',1,2,5,'VLT','completed','2026-01-01')");
    unset($pdo);
    (new Vault24\Support\Database($f))->migrate();
    $db = new Vault24\Support\Database($f);
    T::same('', $db->one("SELECT note FROM transfers WHERE reference='TRF-OLD'")['note']);
    foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x);
});
