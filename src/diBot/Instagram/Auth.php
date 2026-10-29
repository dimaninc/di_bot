<?php
namespace diBot\Instagram;

use diBot\Config;
use diBot\Exception\ApiException;
use diBot\Http\{Client, CurlClient, Request};

/**
 * Business Login for Instagram: ссылка входа, обмен кода, долгоживущий токен и его
 * обновление. Хранение токена и график обновления – дело приложения.
 *
 * С $baseUrl (прокси) все вызовы идут на него: обмен кода – <baseUrl>/oauth/access_token
 * (прокси шлёт его на api.instagram.com), остальное – по обычным путям graph.instagram.com.
 */
final class Auth
{
    public const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    public const CODE_BASE_URL = 'https://api.instagram.com';
    public const GRAPH_BASE_URL = 'https://graph.instagram.com';

    private Client $http;

    public function __construct(
        private readonly string $appId,
        #[\SensitiveParameter] private readonly string $appSecret,
        private readonly string $redirectUri,
        ?Client $http = null,
        private readonly string $baseUrl = '',
        #[\SensitiveParameter] private readonly string $proxySecret = '',
        private readonly int $timeout = 30
    ) {
        if (!preg_match('/^[0-9]{1,32}$/D', $appId) || $appSecret === '') {
            throw new \InvalidArgumentException('Invalid Instagram app credentials');
        }
        Config::assertHttpsUrl($redirectUri);
        if ($baseUrl !== '') {
            Config::assertHttpsUrl($baseUrl);
            if (parse_url($baseUrl, PHP_URL_QUERY) !== null || $proxySecret === '') {
                throw new \InvalidArgumentException('Instagram proxy requires a secret');
            }
        }
        foreach ([$appSecret, $proxySecret] as $secret) {
            if (preg_match('/[\x00-\x20\x7f]/', $secret)) {
                throw new \InvalidArgumentException(
                    'Secrets must not contain control characters or spaces'
                );
            }
        }
        $this->http = $http ?? new CurlClient();
    }

    /** @param list<string> $scopes например instagram_business_basic, …_manage_messages */
    public function authorizeUrl(string $state, array $scopes): string
    {
        if ($state === '' || $scopes === []) {
            throw new \InvalidArgumentException('State and scopes are required');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !preg_match('/^[a-z_]{1,64}$/D', $scope)) {
                throw new \InvalidArgumentException('Invalid scope');
            }
        }
        return self::AUTHORIZE_URL .
            '?' .
            http_build_query(
                [
                    'client_id' => $this->appId,
                    'redirect_uri' => $this->redirectUri,
                    'response_type' => 'code',
                    'scope' => implode(',', $scopes),
                    'state' => $state,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }

    /** Короткоживущий (1 час) токен и user_id; код одноразовый, живёт час. */
    public function exchangeCode(#[\SensitiveParameter] string $code): Token
    {
        // Meta дописывает к адресу возврата «#_», это не часть кода.
        $code = preg_replace('/#_?$/D', '', $code);
        if ($code === '') {
            throw new \InvalidArgumentException('Empty authorization code');
        }
        $body = http_build_query(
            [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
                'code' => $code,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $data = $this->call(
            'POST',
            ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : self::CODE_BASE_URL) .
                '/oauth/access_token',
            $body
        );
        // В справочнике ответ обёрнут в data[], на практике бывает плоским.
        if (is_array($data['data'][0] ?? null)) {
            $data = $data['data'][0];
        }
        $permissions = $data['permissions'] ?? [];
        if (is_string($permissions)) {
            $permissions = $permissions === '' ? [] : explode(',', $permissions);
        }
        $userId = $data['user_id'] ?? null;
        return $this->token(
            $data,
            is_string($userId) || is_int($userId) ? (string) $userId : '',
            is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : []
        );
    }

    /** Долгоживущий токен (60 дней) в обмен на короткоживущий. */
    public function longLived(#[\SensitiveParameter] string $shortToken): Token
    {
        return $this->token(
            $this->graph('/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->appSecret,
                'access_token' => $shortToken,
            ])
        );
    }

    /** Новый токен на 60 дней; старому должно быть не меньше суток, и он не истёк. */
    public function refresh(#[\SensitiveParameter] string $longToken): Token
    {
        return $this->token(
            $this->graph('/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longToken,
            ])
        );
    }

    public function __debugInfo(): array
    {
        return ['appId' => $this->appId, 'appSecret' => '[redacted]'];
    }

    /**
     * Справочник принимает эти параметры только в query (GET), и секреты попадают в URL.
     * Поэтому URL не логируется нигде, а ошибки клиента очищаются до безопасной причины.
     */
    private function graph(string $path, #[\SensitiveParameter] array $query): array
    {
        return $this->call(
            'GET',
            ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : self::GRAPH_BASE_URL) .
                $path .
                '?' .
                http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function token(array $data, string $userId = '', array $permissions = []): Token
    {
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ApiException(200, 'invalid_token_response');
        }
        $expires = $data['expires_in'] ?? 0;
        return new Token(
            $token,
            $userId,
            is_int($expires) && $expires > 0 ? $expires : 0,
            $permissions
        );
    }

    private function call(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] ?string $body = null
    ): array {
        $headers = $body === null ? [] : ['Content-Type: application/x-www-form-urlencoded'];
        if ($this->baseUrl !== '') {
            $headers[] = 'X-Proxy-Auth: ' . $this->proxySecret;
        }
        try {
            $response = $this->http->send(
                new Request($method, $url, $headers, $body, $this->timeout)
            );
        } catch (\Throwable $e) {
            throw ApiException::fromTransport($e);
        }
        $error = Errors::classify($response);
        if ($error !== null) {
            throw $error;
        }
        return $response->json();
    }
}
