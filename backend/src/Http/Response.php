<?php
declare(strict_types=1);

namespace Vault24\Http;

final class Response
{
    public function __construct(public int $status, public ?array $payload, public array $headers = []) {}

    public static function data(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, ['success' => true, 'data' => $data === [] ? new \stdClass() : $data], $headers);
    }

    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['success' => false, 'error' => $error], $headers);
    }

    public function body(): string
    {
        return $this->payload === null ? '' : json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        header_remove('X-Powered-By'); // do not advertise the runtime version
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body();
    }
}
