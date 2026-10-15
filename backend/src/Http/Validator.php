<?php
declare(strict_types=1);

namespace Vault24\Http;

use Vault24\Auth\CommonPasswords;

/** Allowlist-based, strictly typed input validation. Collects errors, then fail() throws a 422. */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data) {}

    /** Reject unexpected fields (role, status, balance, owner ids, ...). */
    public function onlyKeys(array $allowed): self
    {
        foreach (array_keys($this->data) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $this->errors[(string) $k] = 'Unexpected field.';
            }
        }
        return $this;
    }

    public function has(string $f): bool
    {
        return array_key_exists($f, $this->data);
    }

    public function string(string $f, int $min, int $max, bool $trim = true): ?string
    {
        $v = $this->data[$f] ?? null;
        if (!is_string($v)) {
            $this->errors[$f] ??= 'A string is required.';
            return null;
        }
        if ($trim) {
            $v = trim($v);
        }
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min || $len > $max) {
            $this->errors[$f] ??= "Length must be between $min and $max characters.";
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
            $this->errors[$f] ??= 'Control characters are not allowed.';
            return null;
        }
        return $v;
    }

    public function email(string $f): ?string
    {
        $v = $this->string($f, 3, 254);
        if ($v === null) {
            return null;
        }
        $v = mb_strtolower($v, 'UTF-8');
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$f] ??= 'A valid email address is required.';
            return null;
        }
        return $v;
    }

    public function password(string $f, int $maxBytes, ?string $email = null): ?string
    {
        $v = $this->data[$f] ?? null;
        if (!is_string($v)) {
            $this->errors[$f] ??= 'A string is required.';
            return null;
        }
        if (mb_strlen($v, 'UTF-8') < 12) {
            $this->errors[$f] ??= 'Password must be at least 12 characters.';
            return null;
        }
        if (strlen($v) > $maxBytes) {
            $this->errors[$f] ??= "Password must be at most $maxBytes bytes.";
            return null;
        }
        if (CommonPasswords::isWeak($v, $email)) {
            $this->errors[$f] ??= 'This password is too common or predictable.';
            return null;
        }
        return $v;
    }

    public function integer(string $f, int $min, int $max): ?int
    {
        $v = $this->data[$f] ?? null;
        if (!is_int($v)) { // floats, numeric strings, bools and big ints are all rejected
            $this->errors[$f] ??= 'An integer is required.';
            return null;
        }
        if ($v < $min || $v > $max) {
            $this->errors[$f] ??= "Value must be between $min and $max.";
            return null;
        }
        return $v;
    }

    public function oneOf(string $f, array $allowed): ?string
    {
        $v = $this->data[$f] ?? null;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$f] ??= 'Must be one of: ' . implode(', ', $allowed) . '.';
            return null;
        }
        return $v;
    }

    public function pattern(string $f, string $regex, string $message): ?string
    {
        $v = $this->data[$f] ?? null;
        if (!is_string($v) || !preg_match($regex, $v)) {
            $this->errors[$f] ??= $message;
            return null;
        }
        return $v;
    }

    public function fail(): void
    {
        if ($this->errors !== []) {
            throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', $this->errors);
        }
    }

    /** @return array{0:int,1:int} page, per_page (capped) */
    public static function pagination(array $query, int $maxPerPage = 50): array
    {
        $out = [];
        foreach (['page' => [1, 100000, 1], 'per_page' => [1, $maxPerPage, 20]] as $k => [$min, $max, $default]) {
            if (!isset($query[$k])) {
                $out[] = $default;
                continue;
            }
            if (!is_string($query[$k]) || !preg_match('/^\d{1,6}$/', $query[$k]) || (int) $query[$k] < $min || (int) $query[$k] > $max) {
                throw new HttpException(422, 'validation_failed', 'The request contains invalid fields.', [$k => "Must be an integer between $min and $max."]);
            }
            $out[] = (int) $query[$k];
        }
        return $out;
    }
}
