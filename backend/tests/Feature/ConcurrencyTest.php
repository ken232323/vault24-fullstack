<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

/** Launch N worker processes at once against the same database file; return their decoded results. */
function spawn_workers(TestApp $a, array $jobs): array
{
    $procs = [];
    $startAt = microtime(true) + 1.0; // every worker boots first, then all fire at this instant
    foreach ($jobs as $i => [$token, $dest, $amount, $key]) {
        $cmd = [PHP_BINARY, __DIR__ . '/../worker.php', $a->dbFile, $a->logFile, $token, $dest, (string) $amount, $key, sprintf('%.6F', $startAt)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($p);
        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("worker $i failed: $stdout $stderr");
        }
        $out[] = $decoded;
    }
    return $out;
}

test('concurrency: 8 simultaneous transfers cannot overspend one balance (3 succeed, 5 rejected)', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 1000); $bob = $a->user('Bob', 0);
    $jobs = [];
    for ($i = 0; $i < 8; $i++) $jobs[] = [$alice['token'], $bob['ref'], 300, 'conc-key-' . $i . '-abcdef'];
    $res = spawn_workers($a, $jobs);
    $ok = count(array_filter($res, fn ($r) => $r['status'] === 201));
    $insufficient = count(array_filter($res, fn ($r) => $r['status'] === 422 && $r['code'] === 'insufficient_funds'));
    T::same(3, $ok, 'exactly floor(1000/300) succeed: ' . json_encode($res));
    T::same(5, $insufficient);
    T::same(100, $a->balance($alice['id'])); T::same(900, $a->balance($bob['id']));
    T::same(3, $a->count('transfers')); T::same(6, $a->count('ledger_entries'));
    T::same(1000, $a->balance($alice['id']) + $a->balance($bob['id']), 'money is conserved');
});

test('concurrency: 6 simultaneous replays of ONE idempotency key debit exactly once', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 1000); $bob = $a->user('Bob', 0);
    $jobs = array_fill(0, 6, [$alice['token'], $bob['ref'], 100, 'same-key-concurrent-1']);
    $res = spawn_workers($a, $jobs);
    T::same(1, count(array_filter($res, fn ($r) => $r['status'] === 201)), 'one creation: ' . json_encode($res));
    T::same(5, count(array_filter($res, fn ($r) => $r['status'] === 200)), 'five replays');
    T::same(1, count(array_unique(array_column($res, 'ref'))), 'all return the same reference');
    T::same(900, $a->balance($alice['id'])); T::same(100, $a->balance($bob['id']));
    T::same(1, $a->count('transfers')); T::same(2, $a->count('ledger_entries'));
});

test('concurrency: opposite-direction simultaneous transfers (A<->B) never deadlock or lose money', function () {
    $a = new TestApp();
    $alice = $a->user('Alice', 1000); $bob = $a->user('Bob', 1000);
    $jobs = [];
    for ($i = 0; $i < 4; $i++) {
        $jobs[] = [$alice['token'], $bob['ref'], 100, 'a2b-key-' . $i . '-abcdefg'];
        $jobs[] = [$bob['token'], $alice['ref'], 100, 'b2a-key-' . $i . '-abcdefg'];
    }
    $res = spawn_workers($a, $jobs);
    T::same(8, count(array_filter($res, fn ($r) => $r['status'] === 201)), json_encode($res));
    T::same(2000, $a->balance($alice['id']) + $a->balance($bob['id']));
    T::same(1000, $a->balance($alice['id'])); T::same(1000, $a->balance($bob['id']));
});
