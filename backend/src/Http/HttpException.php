<?php
declare(strict_types=1);

namespace Vault24\Http;

/** An error that is safe to show to the client (status + stable machine code + generic message). */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
