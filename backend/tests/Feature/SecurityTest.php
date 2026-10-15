<?php
declare(strict_types=1);

use Vault24\Audit\AuditRedactor;
use Vault24\Support\Money;
use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

test('audit redaction: DB audit rows and the app log never contain passwords, tokens, hashes or Authorization values', function () {
    $a = new TestApp();
    $pw = 'Very-Secret-Pass-Phrase-42';
    $a->call('POST', '/api/v1/auth/register', ['name' => 'Eve', 'email' => 'eve@vault24.test', 'password' => $pw]);
    $a->call('POST', '/api/v1/auth/login', ['email' => 'eve@vault24.test', 'password' => 'Wrong-Wrong-Wrong-1']);
    $login = $a->call('POST', '/api/v1/auth/login', ['email' => 'eve@vault24.test', 'password' => $pw]);
    $token = $login['json']['data']['token'];
    $bob = $a->user('Bob', 0);
    $a->db()->run('UPDATE accounts SET balance_minor = 5000 WHERE user_id = (SELECT id FROM users WHERE email = ?)', ['eve@vault24.test']);
    $eve = ['token' => $token];
    $a->transfer($eve, $bob['ref'], 100);
    $a->transfer($eve, $bob['ref'], 99999999);
    $a->call('PATCH', '/api/v1/me/profile', ['name' => 'Eve E'], $token);
    $a->k->transfers->faultAfterDebit = fn () => throw new \RuntimeException('boom');
    $a->transfer($eve, $bob['ref'], 100);
    $a->call('POST', '/api/v1/auth/logout', null, $token);

    $audit = json_encode($a->db()->all('SELECT * FROM audit_events'));
    $log = (string) file_get_contents($a->logFile);
    foreach ([$pw, $token, 'Wrong-Wrong-Wrong-1', '$argon2', '$2y$', 'Bearer'] as $secret) {
        T::notContains($secret, $audit, 'audit contains secret');
        T::notContains($secret, $log, 'log contains secret');
    }
    foreach (['register.success', 'login.failure', 'login.success', 'transfer.created', 'transfer.failed', 'profile.updated', 'logout'] as $ev) {
        T::true($a->db()->one('SELECT 1 FROM audit_events WHERE event_type = ?', [$ev]) !== null, "event $ev recorded");
    }
    $row = $a->db()->one("SELECT * FROM audit_events WHERE event_type='transfer.created'");
    T::true($row['request_id'] !== null && $row['actor_user_id'] !== null, 'who/what/request id present');
});

test('audit redactor: allowlist drops unknown keys, deny-patterns drop secrets, unknown events are refused', function () {
    $c = AuditRedactor::clean('login.failure', ['reason' => 'bad_credentials', 'password' => 'hunter2', 'token' => 'abc', 'authorization' => 'Bearer x', 'extra' => 'nope']);
    T::same(['reason' => 'bad_credentials'], $c);
    T::same(['reason' => '[redacted]'], AuditRedactor::clean('login.failure', ['reason' => 'Bearer abc.def.ghi']));
    T::same(['changed_fields' => ['name']], AuditRedactor::clean('profile.updated', ['changed_fields' => ['name'], 'new_password' => 'x']));
    T::same(200, strlen(AuditRedactor::clean('admin.action', ['reason' => str_repeat('a', 500)])['reason']), 'long values truncated');
    $a = new TestApp();
    T::throws(fn () => $a->k->audit->record('made.up', null, 'success'));
});

test('error handling: 404/405 are generic JSON with request id; 500 hides internals', function () {
    $a = new TestApp();
    $n = $a->call('GET', '/definitely/not/here');
    T::same(404, $n['status']); T::same('not_found', $n['json']['error']['code']);
    T::same($n['headers']['X-Request-Id'], $n['json']['request_id']);
    $m = $a->call('DELETE', '/api/v1/me');
    T::same(405, $m['status']); T::contains('GET', $m['headers']['Allow']);
    $a->k->transfers->faultAfterDebit = fn () => throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: UNIQUE constraint failed: accounts.account_ref');
    $x = $a->user('Alice', 500); $y = $a->user('Bob');
    $r = $a->transfer($x, $y['ref'], 10);
    T::same(500, $r['status']);
    foreach (['SQLSTATE', 'UNIQUE', 'accounts', 'Stack', '#0', 'vendor', 'src/'] as $leak) T::notContains($leak, $r['raw']);
});

test('security headers on every response; HSTS only when configured; health is minimal', function () {
    $a = new TestApp();
    $r = $a->call('GET', '/health');
    T::same(['status' => 'ok'], $r['json']['data']);
    T::same('nosniff', $r['headers']['X-Content-Type-Options']); T::same('DENY', $r['headers']['X-Frame-Options']);
    T::same('no-store', $r['headers']['Cache-Control']); T::contains("default-src 'none'", $r['headers']['Content-Security-Policy']);
    T::same('no-referrer', $r['headers']['Referrer-Policy']);
    T::true(!isset($r['headers']['Strict-Transport-Security']));
    $h = (new TestApp(['hsts' => true]))->call('GET', '/health');
    T::contains('max-age=', $h['headers']['Strict-Transport-Security']);
});

test('CORS: only allowlisted origins are echoed, never a wildcard; preflight answered without auth', function () {
    $a = new TestApp();
    $ok = $a->call('GET', '/health', null, null, ['Origin' => 'https://app.vault24.test']);
    T::same('https://app.vault24.test', $ok['headers']['Access-Control-Allow-Origin']);
    $bad = $a->call('GET', '/health', null, null, ['Origin' => 'https://evil.example']);
    T::true(!isset($bad['headers']['Access-Control-Allow-Origin']), 'hostile origin gets no CORS grant');
    T::true(!isset($bad['headers']['Access-Control-Allow-Credentials']));
    $pre = $a->call('OPTIONS', '/api/v1/transfers', null, null, ['Origin' => 'https://app.vault24.test', 'Access-Control-Request-Method' => 'POST']);
    T::same(204, $pre['status']); T::contains('Idempotency-Key', $pre['headers']['Access-Control-Allow-Headers']);
    $preBad = $a->call('OPTIONS', '/api/v1/transfers', null, null, ['Origin' => 'https://evil.example']);
    T::true(!isset($preBad['headers']['Access-Control-Allow-Origin']));
});

test('money: integer minor units only; formatting is exact; no float arithmetic in application code', function () {
    T::same('12.50', Money::format(1250)); T::same('0.05', Money::format(5)); T::same('0.00', Money::format(0));
    T::same('1.00', Money::format(100)); T::same('9999999999.99', Money::format(999999999999));
    $bad = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src')) as $f) {
        if ($f->getExtension() !== 'php') continue;
        $code = (string) file_get_contents($f->getPathname());
        if (preg_match('/\b(floatval|number_format|round|ceil|floor|bcadd|bcsub)\s*\(|\(float\)|\(double\)|\bfloat\b\s+\$/', $code)) $bad[] = basename($f->getPathname());
    }
    T::same([], $bad, 'float/rounding functions found in: ' . implode(',', $bad));
});

test('no raw SQL built from user input: every query in src/ uses bound parameters', function () {
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src')) as $f) {
        if ($f->getExtension() !== 'php') continue;
        $code = (string) file_get_contents($f->getPathname());
        // flag SQL strings that interpolate request-ish variables
        if (preg_match('/->(run|one|all|insert)\(\s*"[^"]*(SELECT|INSERT|UPDATE|DELETE)[^"]*\$(?!w\b|where\b)(?:req|r|body|query|name|email|password|id|ref)\b/i', $code)) $offenders[] = basename($f->getPathname());
    }
    T::same([], $offenders);
});
