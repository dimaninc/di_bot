<?php
namespace diBot;

readonly final class Config
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public string $baseUrl = '',
        #[\SensitiveParameter] public string $webhookSecret = '',
        #[\SensitiveParameter] public string $proxySecret = '',
        public ?string $caBundle = null,
        public int $connectTimeout = 10,
        public int $requestTimeout = 30,
        public int $uploadTimeout = 60,
        public bool $retryMediaInCli = false,
        public int $maxDownloadBytes = 5 * 1024 * 1024,
        public int $downloadTimeout = 30
    ) {
        foreach ([$token, $proxySecret, $webhookSecret] as $secret) {
            if (preg_match('/[\x00-\x20\x7f]/', $secret)) {
                throw new \InvalidArgumentException(
                    'Secrets must not contain control characters or spaces'
                );
            }
        }
        if ($baseUrl !== '') {
            self::assertHttpsUrl($baseUrl);
        }
        if (min($connectTimeout, $requestTimeout, $uploadTimeout, $downloadTimeout) < 1) {
            throw new \InvalidArgumentException('Timeouts must be positive');
        }
        if ($maxDownloadBytes < 1 || $maxDownloadBytes > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Download limit must be between 1 byte and 20 MiB');
        }
        if ($caBundle !== null && !is_readable($caBundle)) {
            throw new \InvalidArgumentException('CA bundle is not readable');
        }
    }

    public static function assertHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            !$parts ||
            ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment']) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        ) {
            throw new \InvalidArgumentException(
                'Expected an absolute HTTPS URL without credentials or fragment'
            );
        }
    }

    public function __debugInfo(): array
    {
        return [
            'token' => '[redacted]',
            'webhookSecret' => '[redacted]',
            'proxySecret' => '[redacted]',
        ];
    }
}
