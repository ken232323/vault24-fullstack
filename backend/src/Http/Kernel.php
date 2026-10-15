<?php
declare(strict_types=1);

namespace Vault24\Http;

use Vault24\Audit\AuditService;
use Vault24\Auth\AuthService;
use Vault24\Auth\LoginThrottle;
use Vault24\Auth\PasswordHasher;
use Vault24\Auth\Principal;
use Vault24\Controllers\AccountController;
use Vault24\Controllers\AdminController;
use Vault24\Controllers\AuthController;
use Vault24\Controllers\HealthController;
use Vault24\Controllers\ProfileController;
use Vault24\Controllers\TransferController;
use Vault24\Policies\TransferPolicy;
use Vault24\Support\Config;
use Vault24\Support\Database;
use Vault24\Support\Logger;
use Vault24\Support\RateLimiter;
use Vault24\Transfers\TransferService;

/**
 * Request pipeline: size limit -> CORS -> route match -> authentication -> role check ->
 * throttling -> controller. Routes are DENY BY DEFAULT: anything not 'public' needs a valid
 * token AND one of the listed roles.
 */
final class Kernel
{
    public Database $db;
    public Logger $log;
    public AuditService $audit;
    public PasswordHasher $hasher;
    public AuthService $auth;
    public TransferService $transfers;
    private RateLimiter $limiter;
    /** @var list<array> */
    private array $routes = [];

    public function __construct(public readonly Config $cfg)
    {
        $this->db = new Database((string) $cfg->get('db_path'));
        $this->log = new Logger((string) $cfg->get('log_path'));
        $this->audit = new AuditService($this->db, $this->log);
        $this->hasher = new PasswordHasher((int) $cfg->get('password_memory_cost'), (int) $cfg->get('password_time_cost'));
        $this->auth = new AuthService($this->db, $cfg, $this->hasher, new LoginThrottle($this->db, $cfg), $this->audit);
        $this->transfers = new TransferService($this->db, $cfg, $this->audit);
        $this->limiter = new RateLimiter($this->db);
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $authC = new AuthController($this->auth, $this->audit, $this->hasher, $this->cfg);
        $profileC = new ProfileController($this->db, $this->audit);
        $accountC = new AccountController($this->db);
        $transferC = new TransferController($this->transfers, new TransferPolicy($this->db), $this->db, $this->cfg);
        $adminC = new AdminController($this->db, $this->audit, $this->auth, $this->hasher);
        $health = new HealthController();

        $any = ['customer', 'staff', 'admin'];
        $backoffice = ['staff', 'admin'];
        $this->route('GET', '/health', fn () => $health->show(), 'public');
        $this->route('POST', '/api/v1/auth/register', fn ($r) => $authC->register($r), 'public', ['name' => 'register', 'limit' => 10, 'window' => 3600, 'by' => 'ip']);
        $this->route('POST', '/api/v1/auth/login', fn ($r) => $authC->login($r), 'public', ['name' => 'login', 'limit' => 60, 'window' => 60, 'by' => 'ip']);
        // Admin portal sign-in: only staff/admin may use it, and it sets the separate admin cookie.
        $this->route('POST', '/api/v1/admin/auth/login', fn ($r) => $authC->login($r, $backoffice), 'public', ['name' => 'login', 'limit' => 60, 'window' => 60, 'by' => 'ip']);
        $this->route('POST', '/api/v1/admin/auth/logout', fn ($r, $p) => $authC->logout($r, $p), $backoffice);
        $this->route('GET', '/api/v1/admin/auth/me', fn ($r, $p) => $authC->me($r, $p), $backoffice);
        $this->route('POST', '/api/v1/auth/logout', fn ($r, $p) => $authC->logout($r, $p), $any);
        $this->route('GET', '/api/v1/me', fn ($r, $p) => $authC->me($r, $p), $any);
        $this->route('PATCH', '/api/v1/me/profile', fn ($r, $p) => $profileC->update($r, $p), $any, ['name' => 'profile', 'limit' => 30, 'window' => 60, 'by' => 'user']);
        $this->route('GET', '/api/v1/account', fn ($r, $p) => $accountC->show($r, $p), ['customer']);
        $this->route('GET', '/api/v1/transactions', fn ($r, $p) => $accountC->transactions($r, $p), ['customer']);
        $this->route('POST', '/api/v1/transfers', fn ($r, $p) => $transferC->create($r, $p), ['customer'], ['name' => 'transfer', 'limit' => (int) $this->cfg->get('transfer_rate_limit'), 'window' => 60, 'by' => 'user']);
        $this->route('GET', '/api/v1/transfers/{reference}', fn ($r, $p, $a) => $transferC->show($r, $p, $a), $any);
        $this->route('GET', '/api/v1/admin/audit-events', fn ($r, $p) => $adminC->auditEvents($r, $p), $backoffice); // staff: no metadata
        $this->route('GET', '/api/v1/admin/customers', fn ($r, $p) => $adminC->customers($r, $p), $backoffice);      // staff: no balances
        $this->route('GET', '/api/v1/admin/security-events', fn ($r, $p) => $adminC->securityEvents($r, $p), $backoffice);
        $this->route('GET', '/api/v1/admin/staff', fn ($r, $p) => $adminC->staffList($r, $p), ['admin']);
        $this->route('POST', '/api/v1/admin/staff', fn ($r, $p) => $adminC->createStaff($r, $p), ['admin'], ['name' => 'admin', 'limit' => 10, 'window' => 60, 'by' => 'user']);
        $this->route('POST', '/api/v1/admin/users/{id}/lock', fn ($r, $p, $a) => $adminC->setLock($r, $p, $a, true), ['admin'], ['name' => 'admin', 'limit' => 10, 'window' => 60, 'by' => 'user']);
        $this->route('POST', '/api/v1/admin/users/{id}/unlock', fn ($r, $p, $a) => $adminC->setLock($r, $p, $a, false), ['admin'], ['name' => 'admin', 'limit' => 10, 'window' => 60, 'by' => 'user']);
    }

    /** @param 'public'|list<string> $access */
    private function route(string $method, string $pattern, \Closure $handler, string|array $access, ?array $throttle = null): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'access', 'throttle');
    }

    public function handle(Request $req): Response
    {
        $rid = bin2hex(random_bytes(8));
        $this->audit->setRequestId($rid);
        try {
            $res = $this->dispatch($req);
        } catch (HttpException $e) {
            $res = Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers);
        } catch (\Throwable $e) {
            $this->log->error($rid, $e); // details go to the log only, never to the client
            $res = Response::error(500, 'server_error', 'An unexpected error occurred.');
        }
        return $this->finalize($res, $req, $rid);
    }

    private function dispatch(Request $req): Response
    {
        if (strlen($req->rawBody) > (int) $this->cfg->get('max_body_bytes')) {
            throw new HttpException(413, 'payload_too_large', 'Request body is too large.');
        }
        if ($req->method === 'OPTIONS') {
            return new Response(204, null);
        }

        $matched = null;
        $params = [];
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $req->path, $m)) {
                if ($route['method'] === $req->method) {
                    $matched = $route;
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    break;
                }
                $allowed[] = $route['method'];
            }
        }
        if ($matched === null) {
            if ($allowed !== []) {
                throw new HttpException(405, 'method_not_allowed', 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
            }
            throw new HttpException(404, 'not_found', 'Resource not found.');
        }

        $principal = null;
        if ($matched['access'] !== 'public') {
            $token = $req->bearer();
            $viaCookie = false;
            if ($token === null) {
                $token = $req->cookie(SessionCookie::nameFor($req->path));
                $viaCookie = $token !== null;
            }
            if ($viaCookie) {
                $this->assertSameSiteRequest($req); // CSRF defence for cookie-authenticated requests
            }
            if ($token === null) {
                throw new HttpException(401, 'authentication_required', 'Authentication is required.', [], ['WWW-Authenticate' => 'Bearer']);
            }
            $principal = $this->auth->authenticate($token);
            if ($principal === null) {
                throw new HttpException(401, 'invalid_token', 'Authentication is required.', [], ['WWW-Authenticate' => 'Bearer']);
            }
            if (!in_array($principal->role, $matched['access'], true)) {
                $this->audit->record('authz.denied', $principal->userId, 'denied', 'route', null, ['route' => $matched['pattern'], 'required_roles' => $matched['access']]);
                throw new HttpException(403, 'forbidden', 'You are not allowed to perform this action.');
            }
        }

        if ($matched['throttle'] !== null) {
            $t = $matched['throttle'];
            $who = $t['by'] === 'user' && $principal instanceof Principal ? 'u' . $principal->userId : 'ip' . $req->ip;
            $wait = $this->limiter->hit($t['name'] . ':' . $who, $t['limit'], $t['window']);
            if ($wait > 0) {
                throw new HttpException(429, 'rate_limited', 'Too many requests. Please try again later.', [], ['Retry-After' => (string) $wait]);
            }
        }
        return ($matched['handler'])($req, $principal, $params);
    }

    /**
     * Cookie sessions are sent automatically by browsers, so state-changing requests must prove they
     * come from our own front-end: a custom header (cross-site forms cannot set it without a CORS
     * preflight, which our allowlist denies) plus an Origin check. SameSite=Strict is the third layer.
     */
    private function assertSameSiteRequest(Request $req): void
    {
        if (in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $deny = new HttpException(403, 'csrf_rejected', 'Request rejected.');
        if (!$req->isBrowserClient()) {
            throw $deny;
        }
        $origin = $req->header('origin');
        if ($origin !== null) {
            $host = (string) parse_url($origin, PHP_URL_HOST) . (($port = parse_url($origin, PHP_URL_PORT)) ? ':' . $port : '');
            if (!in_array($origin, (array) $this->cfg->get('cors_origins'), true) && $host !== (string) $req->header('host')) {
                throw $deny;
            }
        }
    }

    private function finalize(Response $res, Request $req, string $rid): Response
    {
        if ($res->payload !== null) {
            $res->payload['request_id'] = $rid;
        }
        $h = $res->headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-Id' => $rid,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()',
        ];
        if ($this->cfg->get('hsts')) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        $origin = $req->header('origin');
        if ($origin !== null) {
            $h['Vary'] = 'Origin';
            if (in_array($origin, (array) $this->cfg->get('cors_origins'), true)) { // explicit allowlist only, never "*"
                $h['Access-Control-Allow-Origin'] = $origin;
                $h['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, Idempotency-Key, X-Requested-With';
                $h['Access-Control-Allow-Credentials'] = 'true'; // only ever paired with an explicit origin
                $h['Access-Control-Allow-Methods'] = 'GET, POST, PATCH, OPTIONS';
                $h['Access-Control-Max-Age'] = '600';
            }
        }
        $res->headers = $h;
        return $res;
    }
}
