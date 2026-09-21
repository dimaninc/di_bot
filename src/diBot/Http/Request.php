<?php
namespace diBot\Http;

readonly final class Request
{
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public int $timeout = 30,
        public int $connectTimeout = 10,
        public ?string $caBundle = null,
        public bool $publicUpload = false
    ) {
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'bodyBytes' => strlen($this->body ?? '')];
    }
}
