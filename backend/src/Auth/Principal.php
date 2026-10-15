<?php
declare(strict_types=1);

namespace Vault24\Auth;

/** The authenticated caller. Identity ALWAYS comes from here, never from request fields. */
final class Principal
{
    public function __construct(
        public readonly int $userId,
        public readonly string $publicId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly int $sessionId,
    ) {}

    public function isStaffOrAdmin(): bool
    {
        return $this->role === 'staff' || $this->role === 'admin';
    }
}
