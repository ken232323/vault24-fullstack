<?php
declare(strict_types=1);
// Child process used by the concurrency tests: one HTTP-layer transfer against a shared DB file.
require __DIR__ . '/../src/autoload.php';

use Vault24\Http\Kernel;
use Vault24\Http\Request;
use Vault24\Support\Config;

[, $db, $log, $token, $dest, $amount, $key, $startAt] = $argv;
$k = new Kernel(Config::make(dirname(__DIR__), ['env' => 'testing', 'db_path' => $db, 'log_path' => $log, 'password_memory_cost' => 1024, 'password_time_cost' => 1]));
while (microtime(true) < (float) $startAt) { usleep(200); } // start barrier: all workers fire together
$res = $k->handle(new Request('POST', '/api/v1/transfers', [], [
    'authorization' => "Bearer $token", 'content-type' => 'application/json', 'idempotency-key' => $key,
], json_encode(['destination_account_ref' => $dest, 'amount_minor' => (int) $amount]), '203.0.113.50'));
echo json_encode(['status' => $res->status, 'ref' => $res->payload['data']['reference'] ?? null, 'code' => $res->payload['error']['code'] ?? null]);
