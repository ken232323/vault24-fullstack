<?php
declare(strict_types=1);

namespace Vault24\Http;

use Vault24\Support\Config;

/**
 * Browser session cookies. HttpOnly (JS can never read the token), SameSite=Strict, Secure when configured.
 * The customer app and the admin app use DIFFERENT cookie names (cookies are shared across ports on
 * localhost, and a customer session must never be usable on /admin routes or vice versa).
 */
final class SessionCookie
{
    public const CUSTOMER = 'v24_session';
    public const ADMIN = 'v24_admin_session';

    public static function nameFor(string $path): string
    {
        return str_starts_with($path, '/api/v1/admin/') ? self::ADMIN : self::CUSTOMER;
    }

    public static function set(string $name, string $token, int $maxAge, Config $cfg): string
    {
        return sprintf('%s=%s; Max-Age=%d; Path=/; HttpOnly; SameSite=Strict%s', $name, $token, $maxAge, $cfg->get('cookie_secure') ? '; Secure' : '');
    }

    public static function clear(string $name, Config $cfg): string
    {
        return sprintf('%s=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/; HttpOnly; SameSite=Strict%s', $name, $cfg->get('cookie_secure') ? '; Secure' : '');
    }
}
