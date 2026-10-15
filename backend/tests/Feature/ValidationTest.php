<?php
declare(strict_types=1);

use Vault24\Tests\{T, TestApp};
use function Vault24\Tests\test;

test('injection: SQL metacharacters are stored/handled as data and never alter queries', function () {
    $a = new TestApp();
    $evil = "Robert'); DROP TABLE users;--";
    $r = $a->call('POST', '/api/v1/auth/register', ['name' => $evil, 'email' => 'bobby@vault24.test', 'password' => 'Correct-Horse-Battery-1']);
    T::same(201, $r['status']);
    T::same($evil, $a->db()->one('SELECT name FROM users')['name'], 'stored literally');
    foreach (["' OR '1'='1", "admin'--", '" OR ""="'] as $payload) {
        $l = $a->call('POST', '/api/v1/auth/login', ['email' => $payload, 'password' => $payload]);
        T::true(in_array($l['status'], [401, 422], true), 'login sqli -> ' . $l['status']);
    }
    $u = $a->user('Alice');
    foreach (['1 OR 1=1', '1;DROP TABLE users', '-1', '0', 'abc', '1.5', ''] as $p) {
        T::same(422, $a->call('GET', '/api/v1/transactions?page=' . rawurlencode($p), null, $u['token'])['status'], "page=$p");
    }
    T::true($a->count('users') >= 2, 'users table intact');
});

test('pagination: page size is capped and bounds are enforced', function () {
    $a = new TestApp();
    $u = $a->user('Alice');
    T::same(200, $a->call('GET', '/api/v1/transactions?per_page=50', null, $u['token'])['status']);
    T::same(422, $a->call('GET', '/api/v1/transactions?per_page=51', null, $u['token'])['status']);
    T::same(422, $a->call('GET', '/api/v1/transactions?per_page=0', null, $u['token'])['status']);
    T::same(422, $a->call('GET', '/api/v1/transactions?page[]=1', null, $u['token'])['status']);
});

test('request hygiene: wrong content-type 415, malformed JSON 400, non-object 400, oversize 413, control chars 422', function () {
    $a = new TestApp();
    $ok = ['name' => 'N', 'email' => 'n@vault24.test', 'password' => 'Correct-Horse-Battery-1'];
    T::same(415, $a->call('POST', '/api/v1/auth/register', null, null, ['Content-Type' => 'text/plain'], json_encode($ok))['status']);
    T::same(400, $a->call('POST', '/api/v1/auth/register', null, null, [], '{"name": ')['status']);
    T::same(400, $a->call('POST', '/api/v1/auth/register', null, null, [], '[1,2,3]')['status']);
    T::same(400, $a->call('POST', '/api/v1/auth/register', null, null, [], '"just a string"')['status']);
    T::same(400, $a->call('POST', '/api/v1/auth/register', null, null, [], str_repeat('[', 50) . str_repeat(']', 50))['status'], 'depth limit');
    T::same(413, $a->call('POST', '/api/v1/auth/register', null, null, [], json_encode($ok + ['pad' => str_repeat('x', 20000)]))['status']);
    T::same(422, $a->call('POST', '/api/v1/auth/register', ['name' => "a\x00b"] + $ok)['status']);
    T::same(422, $a->call('POST', '/api/v1/auth/register', ['name' => str_repeat('n', 101)] + $ok)['status']);
    T::same(422, $a->call('POST', '/api/v1/auth/register', ['name' => ['x']] + $ok)['status'], 'type confusion');
    T::same(0, $a->count('users'));
});
