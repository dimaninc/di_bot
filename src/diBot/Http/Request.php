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
        public bool $publicUpload = false,
        public bool $publicDownload = false,
        public int $maxResponseBytes = 8 * 1024 * 1024
    ) {
        if ($maxResponseBytes < 1) {
            throw new \InvalidArgumentException('Invalid response size limit');
        }
    }

    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'bodyBytes' => strlen($this->body ?? '')];
    }
}
