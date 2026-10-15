<?php
declare(strict_types=1);

namespace Vault24\Auth;

/** Wraps PHP's password_* API (Argon2id when available, bcrypt otherwise). No custom crypto. */
final class PasswordHasher
{
    private string $algo;
    private array $options;
    private ?string $dummy = null;
    public readonly int $maxBytes;

    public function __construct(int $memoryCost = 65536, int $timeCost = 4)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algo = PASSWORD_ARGON2ID;
            $this->options = ['memory_cost' => $memoryCost, 'time_cost' => $timeCost, 'threads' => 1];
            $this->maxBytes = 128;
        } else {
            $this->algo = PASSWORD_BCRYPT;
            $this->options = ['cost' => 12];
            $this->maxBytes = 72; // bcrypt ignores bytes beyond 72, so refuse longer input
        }
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algo, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }

    /** Hash verified for unknown emails so response time does not reveal whether an account exists. */
    public function dummyHash(): string
    {
        return $this->dummy ??= $this->hash(bin2hex(random_bytes(16)));
    }
}
