<?php
declare(strict_types=1);

namespace Vault24\Tests;

use Vault24\Http\Kernel;
use Vault24\Http\Request;
use Vault24\Support\Config;
use Vault24\Support\Database;

final class Failure extends \Exception {}

/** Tiny assertion helpers + a per-test isolated app (fresh SQLite file per test). */
final class T
{
    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new Failure(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    public static function true(mixed $v, string $msg = 'expected true'): void
    {
        if ($v !== true) throw new Failure($msg);
    }
    public static function contains(string $needle, string $haystack, string $msg = ''): void
    {
        if (!str_contains($haystack, $needle)) throw new Failure(($msg ?: 'expected to contain') . ": $needle");
    }
    public static function notContains(string $needle, string $haystack, string $msg = ''): void
    {
        if (str_contains($haystack, $needle)) throw new Failure(($msg ?: 'must NOT contain') . ": $needle");
    }
    public static function throws(callable $fn, string $msg = 'expected an exception'): \Throwable
    {
        try { $fn(); } catch (\Throwable $e) { return $e; }
        throw new Failure($msg);
    }
}

final class TestApp
{
    public Kernel $k;
    public string $dbFile;
    public string $logFile;
    private int $seq = 0;

    public function __construct(array $overrides = [])
    {
        $this->dbFile = sys_get_temp_dir() . '/v24test_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->logFile = $this->dbFile . '.log';
        $cfg = Config::make(dirname(__DIR__), $overrides + [
            'env' => 'testing',
            'db_path' => $this->dbFile,
            'log_path' => $this->logFile,
            'password_memory_cost' => 1024, // fast hashing for tests only
            'password_time_cost' => 1,
            'cors_origins' => ['https://app.vault24.test'],
        ]);
        (new Database($this->dbFile))->migrate();
        $this->k = new Kernel($cfg);
    }

    public function __destruct()
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm', $this->logFile] as $f) {
            @unlink($f);
        }
    }

    public function db(): Database { return $this->k->db; }

    /** @return array{status:int,json:?array,headers:array,raw:string} */
    public function call(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = [], ?string $raw = null, string $ip = '203.0.113.7'): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        $h = array_change_key_case($headers, CASE_LOWER);
        $payload = $raw ?? ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
        if ($payload !== '' && !isset($h['content-type'])) $h['content-type'] = 'application/json';
        if ($token !== null) $h['authorization'] = 'Bearer ' . $token;
        $res = $this->k->handle(new Request($method, $path, $query, $h, $payload, $ip));
        $out = $res->body();
        return ['status' => $res->status, 'json' => $out === '' ? null : json_decode($out, true), 'headers' => $res->headers, 'raw' => $out];
    }

    /** Create a user via trusted server code and log in. Balance is bootstrap-only. */
    public function user(string $name, int $balance = 0, string $role = 'customer', ?string $email = null): array
    {
        $this->seq++;
        $email ??= strtolower(preg_replace('/\W/', '', $name)) . $this->seq . '@vault24.test';
        $pw = 'Correct-Horse-Battery-' . $this->seq;
        $u = $this->k->auth->createUser($name, $email, $pw, $role);
        if ($balance > 0) {
            $this->db()->run('UPDATE accounts SET balance_minor = ? WHERE user_id = ?', [$balance, $u['id']]);
        }
        $login = $this->call('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $pw], null, [], null, '198.51.100.' . ($this->seq + 1));
        return ['id' => $u['id'], 'public_id' => $u['public_id'], 'ref' => $u['account_ref'], 'email' => $email, 'password' => $pw, 'token' => $login['json']['data']['token'] ?? '', 'name' => $name];
    }

    public function balance(int $userId): int
    {
        return (int) $this->db()->one('SELECT balance_minor AS b FROM accounts WHERE user_id = ?', [$userId])['b'];
    }

    public function count(string $table): int
    {
        return (int) $this->db()->one("SELECT COUNT(*) AS n FROM $table")['n'];
    }

    public function transfer(array $from, string $destRef, int $amount, ?string $key = null, ?array $extra = null): array
    {
        return $this->call('POST', '/api/v1/transfers', ['destination_account_ref' => $destRef, 'amount_minor' => $amount] + ($extra ?? []), $from['token'], ['Idempotency-Key' => $key ?? 'key-' . bin2hex(random_bytes(6))]);
    }
}

/** Registry + runner. */
final class Runner
{
    /** @var list<array{string,callable}> */
    public static array $tests = [];
    public static function add(string $name, callable $fn): void { self::$tests[] = [$name, $fn]; }
}

function test(string $name, callable $fn): void
{
    Runner::add($name, $fn);
}
