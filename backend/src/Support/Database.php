<?php
declare(strict_types=1);

namespace Vault24\Support;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper (SQLite). All SQL uses bound parameters.
 * transaction() uses BEGIN IMMEDIATE, which takes SQLite's database write lock up front: two
 * concurrent transfers are therefore serialised and the second one re-reads the committed balance.
 * (On MySQL/PostgreSQL the equivalent is SELECT ... FOR UPDATE on both account rows, ascending id.)
 */
final class Database
{
    public PDO $pdo;

    public function __construct(string $path)
    {
        $new = !is_file($path);
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 8000; PRAGMA journal_mode = WAL;');
        if ($new) {
            @chmod($path, 0600);
        }
    }

    public function migrate(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $this->pdo->exec($sql === false ? '' : $sql);
        // Upgrade databases created before `transfers.note` existed (additive, non-destructive).
        $cols = array_column($this->pdo->query('PRAGMA table_info(transfers)')->fetchAll(), 'name');
        if (!in_array('note', $cols, true)) {
            $this->pdo->exec("ALTER TABLE transfers ADD COLUMN note TEXT NOT NULL DEFAULT ''");
        }
    }

    /** @param list<int|string|null> $params positional parameters only */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $stmt->execute();
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            // Always attempt ROLLBACK: PDO::inTransaction() does not reliably track a raw BEGIN IMMEDIATE.
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // nothing to roll back (e.g. the failure was the COMMIT itself)
            }
            throw $e;
        }
    }
}
