<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

test('transfer happy path: exact debit/credit, 2 balanced ledger entries, unique references, correct history', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 100000); $bob = $a->user('Bob', 2500);
    $r = $a->transfer($alice, $bob['ref'], 1250);
    T::same(201, $r['status']);
    $d = $r['json']['data'];
    T::same(['completed', 1250, 'VLT', false], [$d['status'], $d['amount_minor'], $d['currency'], $d['replayed']]);
    T::same(98750, $a->balance($alice['id'])); T::same(3750, $a->balance($bob['id']));
    $le = $a->db()->all('SELECT direction, amount_minor FROM ledger_entries ORDER BY direction');
    T::same([['direction' => 'credit', 'amount_minor' => 1250], ['direction' => 'debit', 'amount_minor' => 1250]], $le);
    $r2 = $a->transfer($alice, $bob['ref'], 1250);
    T::true($r2['json']['data']['reference'] !== $d['reference'], 'unique references');
    T::same(2, $a->call('GET', '/api/v1/transactions', null, $alice['token'])['json']['data']['total']);
    T::same('975.00', $a->call('GET', '/api/v1/account', null, $alice['token'])['json']['data']['balance']);
});

test('insufficient funds: rejected, balances/ledger/transfers unchanged, failure audited', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 1000); $bob = $a->user('Bob', 0);
    $r = $a->transfer($alice, $bob['ref'], 1001);
    T::same(422, $r['status']); T::same('insufficient_funds', $r['json']['error']['code']);
    T::same(1000, $a->balance($alice['id'])); T::same(0, $a->balance($bob['id']));
    T::same(0, $a->count('ledger_entries')); T::same(0, $a->count('transfers')); T::same(0, $a->count('idempotency_keys'));
    T::same(1, (int) $a->db()->one("SELECT COUNT(*) AS n FROM audit_events WHERE event_type='transfer.failed' AND outcome='failure'")['n']);
    T::same(201, $a->transfer($alice, $bob['ref'], 1000)['status'], 'exact balance may be spent');
    T::same(0, $a->balance($alice['id']));
});

test('atomic rollback: a failure after the debit leaves NO partial debit/credit/ledger row; error is generic', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 5000); $bob = $a->user('Bob', 0);
    $a->k->transfers->faultAfterDebit = function () { throw new \RuntimeException('SQLSTATE[HY000] secret internal detail at /srv/app/Transfer.php'); };
    $r = $a->transfer($alice, $bob['ref'], 700, 'rollback-key-1');
    T::same(500, $r['status']); T::same('server_error', $r['json']['error']['code']);
    T::notContains('SQLSTATE', $r['raw']); T::notContains('secret', $r['raw']); T::notContains('.php', $r['raw']); T::notContains('trace', strtolower($r['raw']));
    T::same(5000, $a->balance($alice['id']), 'sender not debited'); T::same(0, $a->balance($bob['id']));
    T::same(0, $a->count('transfers')); T::same(0, $a->count('ledger_entries')); T::same(0, $a->count('idempotency_keys'));
    T::contains('secret internal detail', (string) file_get_contents($a->logFile), 'detail kept in server log only');
    $a->k->transfers->faultAfterDebit = null;
    T::same(201, $a->transfer($alice, $bob['ref'], 700, 'rollback-key-1')['status'], 'same key can be retried after a rolled-back failure');
    T::same(4300, $a->balance($alice['id'])); T::same(700, $a->balance($bob['id']));
});

test('idempotency: same key+payload replays the original; changed payload => 409; no duplicate debit', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 10000); $bob = $a->user('Bob', 0); $carol = $a->user('Carol', 0);
    $first = $a->transfer($alice, $bob['ref'], 1000, 'idem-key-0001');
    $again = $a->transfer($alice, $bob['ref'], 1000, 'idem-key-0001');
    T::same(201, $first['status']); T::same(200, $again['status']);
    T::same($first['json']['data']['reference'], $again['json']['data']['reference']);
    T::same(true, $again['json']['data']['replayed']);
    T::same(9000, $a->balance($alice['id'])); T::same(1, $a->count('transfers')); T::same(2, $a->count('ledger_entries'));
    $conflict = $a->transfer($alice, $bob['ref'], 2000, 'idem-key-0001');
    T::same(409, $conflict['status']); T::same('idempotency_key_conflict', $conflict['json']['error']['code']);
    T::same(409, $a->transfer($alice, $carol['ref'], 1000, 'idem-key-0001')['status'], 'different destination = different payload');
    T::same(9000, $a->balance($alice['id']));
    $other = $a->transfer($bob, $carol['ref'], 100, 'idem-key-0001');
    T::same(201, $other['status'], 'keys are scoped per user: Bob may reuse the same key text');
    T::true($other['json']['data']['reference'] !== $first['json']['data']['reference']);
});

test('idempotency: Idempotency-Key header is mandatory and format-checked', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 1000); $bob = $a->user('Bob', 0);
    $r = $a->call('POST', '/api/v1/transfers', ['destination_account_ref' => $bob['ref'], 'amount_minor' => 100], $alice['token']);
    T::same(422, $r['status']);
    foreach (['short', str_repeat('a', 65), 'has space in it', "bad\x01key12345"] as $k) {
        T::same(422, $a->call('POST', '/api/v1/transfers', ['destination_account_ref' => $bob['ref'], 'amount_minor' => 100], $alice['token'], ['Idempotency-Key' => $k])['status']);
    }
    T::same(1000, $a->balance($alice['id']));
});

test('input validation: bad amounts, destinations, extra fields and currency never move money', function () {
    $a = new TestApp(['transfer_rate_limit' => 1000]); // many probes; rate limiting has its own test
    $alice = $a->user('Alice', 50000); $bob = $a->user('Bob', 0);
    $bad = [0, -5, 1.5, '100', true, null, 10000001, '99999999999999999999', [100]];
    foreach ($bad as $amt) {
        $r = $a->call('POST', '/api/v1/transfers', ['destination_account_ref' => $bob['ref'], 'amount_minor' => $amt], $alice['token'], ['Idempotency-Key' => 'k' . bin2hex(random_bytes(6))]);
        T::same(422, $r['status'], 'amount ' . json_encode($amt));
    }
    $raw = '{"destination_account_ref":"' . $bob['ref'] . '","amount_minor":99999999999999999999}';
    T::same(422, $a->call('POST', '/api/v1/transfers', null, $alice['token'], ['Idempotency-Key' => 'bigint-key-1'], $raw)['status'], 'bigint overflow');
    foreach ([$alice['ref'] => 'self', '9999999999' => 'unknown', 'bad-ref' => 'format', "1';DROP TABLE users;--" => 'sqli'] as $dest => $why) {
        $dest = (string) $dest; // PHP turns all-digit array keys into ints
        T::same(422, $a->transfer($alice, $dest, 100)['status'], "destination: $why");
    }
    foreach (['source_account_ref' => $bob['ref'], 'source_account_id' => 2, 'balance' => 99999999, 'status' => 'completed', 'user_id' => 2] as $k => $v) {
        T::same(422, $a->transfer($alice, $bob['ref'], 100, null, [$k => $v])['status'], "extra field $k");
    }
    T::same(422, $a->transfer($alice, $bob['ref'], 100, null, ['currency' => 'USD'])['status']);
    T::same(201, $a->transfer($alice, $bob['ref'], 100, null, ['currency' => 'VLT'])['status']);
    T::same(49900, $a->balance($alice['id'])); T::same(1, $a->count('transfers'));
    T::same(2, $a->count('users'), 'users table intact');
});

test('frozen destination or source accounts cannot transact', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 5000); $bob = $a->user('Bob', 0);
    $a->db()->run("UPDATE accounts SET status='frozen' WHERE user_id = ?", [$bob['id']]);
    T::same(422, $a->transfer($alice, $bob['ref'], 100)['status']);
    $a->db()->run("UPDATE accounts SET status='active' WHERE user_id = ?", [$bob['id']]);
    $a->db()->run("UPDATE accounts SET status='frozen' WHERE user_id = ?", [$alice['id']]);
    T::same(403, $a->transfer($alice, $bob['ref'], 100)['status']);
    T::same(5000, $a->balance($alice['id']));
});

test('transfer rate limit: a burst from one user is throttled with 429', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 100000); $bob = $a->user('Bob', 0);
    $seen = false;
    for ($i = 0; $i < 25; $i++) {
        if ($a->transfer($alice, $bob['ref'], 1)['status'] === 429) { $seen = true; break; }
    }
    T::true($seen, '429 reached');
    T::same(100000 - $a->count('transfers'), $a->balance($alice['id']), 'balance matches completed transfers exactly');
});

test('database constraints: ledger/transfers/audit are immutable; negative balance, self-transfer, zero amount impossible', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 5000); $bob = $a->user('Bob', 0);
    $a->transfer($alice, $bob['ref'], 100);
    $db = $a->db();
    T::throws(fn () => $db->run('UPDATE ledger_entries SET amount_minor = 1'));
    T::throws(fn () => $db->run('DELETE FROM ledger_entries'));
    T::throws(fn () => $db->run('UPDATE transfers SET amount_minor = 1'));
    T::throws(fn () => $db->run('DELETE FROM transfers'));
    T::throws(fn () => $db->run('UPDATE audit_events SET outcome = \'success\''));
    T::throws(fn () => $db->run('DELETE FROM audit_events'));
    T::throws(fn () => $db->run('UPDATE accounts SET balance_minor = -1 WHERE user_id = ?', [$alice['id']]), 'CHECK balance >= 0');
    T::throws(fn () => $db->run("INSERT INTO transfers (reference, source_account_id, destination_account_id, amount_minor, currency, status) VALUES ('X', 1, 1, 5, 'VLT', 'completed')"), 'CHECK source != destination');
    T::throws(fn () => $db->run("INSERT INTO transfers (reference, source_account_id, destination_account_id, amount_minor, currency, status) VALUES ('Y', 1, 2, 0, 'VLT', 'completed')"), 'CHECK amount > 0');
    T::throws(fn () => $db->run("INSERT INTO users (public_id, name, email, password_hash, role) VALUES ('p','n','e','h','superuser')"), 'role enum');
});
