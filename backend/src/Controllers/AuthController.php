<?php
declare(strict_types=1);

namespace Vault24\Controllers;

use Vault24\Audit\AuditService;
use Vault24\Auth\AuthService;
use Vault24\Auth\PasswordHasher;
use Vault24\Auth\Principal;
use Vault24\Http\Request;
use Vault24\Http\Response;
use Vault24\Http\SessionCookie;
use Vault24\Http\Validator;
use Vault24\Support\Config;

final class AuthController
{
    public function __construct(private AuthService $auth, private AuditService $audit, private PasswordHasher $hasher, private Config $cfg) {}

    public function register(Request $r): Response
    {
        $v = (new Validator($r->json()))->onlyKeys(['name', 'email', 'password']);
        $name = $v->string('name', 1, 100);
        $email = $v->email('email');
        $password = $v->password('password', $this->hasher->maxBytes, $email);
        $v->fail();

        $user = $this->auth->createUser($name, $email, $password); // role + balance + account number decided server-side
        $this->audit->record('register.success', $user['id'], 'success', 'user', $user['public_id']);
        return Response::data(['user' => ['id' => $user['public_id'], 'name' => $name, 'email' => $email, 'role' => 'customer'], 'account_ref' => $user['account_ref']], 201);
    }

    /**
     * Two client modes:
     *  - API/mobile clients: the token is returned in the JSON body (use as a Bearer token).
     *  - Browser apps (send X-Requested-With): the token is set ONLY as an HttpOnly cookie and never
     *    appears in the response body, so injected scripts cannot read it.
     * @param list<string>|null $roles roles allowed to sign in through this entry point
     */
    public function login(Request $r, ?array $roles = null): Response
    {
        $v = (new Validator($r->json()))->onlyKeys(['email', 'password']);
        $email = $v->email('email');
        $password = $v->string('password', 1, 256, false);
        $v->fail();
        $s = $this->auth->login($email, $password, $r->ip, $roles);

        if ($r->isBrowserClient()) {
            return Response::data(['user' => $s['user'], 'expires_at' => $s['expires_at']], 200, [
                'Set-Cookie' => SessionCookie::set(SessionCookie::nameFor($r->path), $s['token'], (int) $this->cfg->get('session_absolute'), $this->cfg),
            ]);
        }
        return Response::data(['token' => $s['token'], 'token_type' => 'Bearer', 'expires_at' => $s['expires_at'], 'user' => $s['user']]);
    }

    public function logout(Request $r, Principal $p): Response
    {
        $this->auth->logout($p);
        $headers = $r->isBrowserClient() ? ['Set-Cookie' => SessionCookie::clear(SessionCookie::nameFor($r->path), $this->cfg)] : [];
        return Response::data(['message' => 'Logged out.'], 200, $headers);
    }

    public function me(Request $r, Principal $p): Response
    {
        return Response::data(['id' => $p->publicId, 'name' => $p->name, 'email' => $p->email, 'role' => $p->role]);
    }
}
