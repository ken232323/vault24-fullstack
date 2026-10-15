<?php
declare(strict_types=1);

namespace Vault24\Auth;

/** Small local blocklist (a real deployment would load a larger breached-password list). */
final class CommonPasswords
{
    private const LIST = [
        'password1234', 'password12345', 'passwordpassword', '123456789012', '1234567890123',
        'qwertyuiop12', 'qwertyuiopasdf', 'letmeinletmein', 'iloveyou1234', 'welcome12345',
        'administrator', 'admin1234567', 'changeme1234', 'abcdefghijkl', 'abc123abc123',
        'password!234', 'passw0rd1234', 'p@ssw0rd1234', 'vault24vault24', 'vault2412345',
    ];

    public static function isWeak(string $password, ?string $email = null): bool
    {
        $p = strtolower($password);
        if (in_array($p, self::LIST, true)) {
            return true;
        }
        if (count(array_unique(mb_str_split($p))) < 4) { // e.g. "aaaaaaaaaaaa", "121212121212"
            return true;
        }
        if ($email !== null && ($p === strtolower($email) || str_contains($p, explode('@', strtolower($email))[0]) && strlen(explode('@', $email)[0]) >= 6)) {
            return true;
        }
        return false;
    }
}
