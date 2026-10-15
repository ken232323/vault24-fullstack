<?php
declare(strict_types=1);

namespace Vault24\Http;

use Vault24\Support\Config;

final class Request
{
    private ?array $json = null;

    /** @param array<string,string> $headers lower-cased header names */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private array $headers,
        public readonly string $rawBody,
        public readonly string $ip,
    ) {}

    public static function fromGlobals(Config $cfg): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $h) {
            if (isset($_SERVER[$k])) {
                $headers[$h] = (string) $_SERVER[$k];
            }
        }
        $max = (int) $cfg->get('max_body_bytes');
        $body = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path !== '/' ) {
            $path = rtrim($path, '/');
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $headers, $body, self::clientIp($headers, $cfg));
    }

    /** Only honour X-Forwarded-For when the direct peer is a configured trusted proxy. */
    private static function clientIp(array $headers, Config $cfg): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) $cfg->get('trusted_proxies');
        if (in_array($remote, $trusted, true) && isset($headers['x-forwarded-for'])) {
            $chain = array_map('trim', explode(',', $headers['x-forwarded-for']));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (!in_array($chain[$i], $trusted, true) && filter_var($chain[$i], FILTER_VALIDATE_IP)) {
                    return $chain[$i];
                }
            }
        }
        return $remote;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Value of a request cookie (parsed from the Cookie header). */
    public function cookie(string $name): ?string
    {
        $raw = $this->header('cookie');
        if ($raw === null) {
            return null;
        }
        foreach (explode(';', $raw) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($k === $name && $v !== null && preg_match('/^[A-Za-z0-9_\-]{20,128}$/', $v)) {
                return $v;
            }
        }
        return null;
    }

    /** Browser clients (the React apps) identify themselves with this custom header: cookie mode. */
    public function isBrowserClient(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    public function bearer(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer ([A-Za-z0-9_\-]{20,128})$/', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Parsed JSON object body. Enforces content type, valid JSON and object-shaped payloads. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        $ct = strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
        if ($ct !== 'application/json') {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        try {
            $data = json_decode($this->rawBody, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'The request body is not valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'invalid_json', 'The request body must be a JSON object.');
        }
        return $this->json = $data;
    }
}
