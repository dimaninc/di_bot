<?php
namespace diBot\Instagram;

use diBot\Config;
use diBot\Exception\ApiException;
use diBot\Http\{Client, CurlClient, Request};

/**
 * Facebook Login for Business (Unified Login): ссылка входа по конфигурации, обмен кода на
 * бессрочный токен BISU и чтение выданного доступа. Хранение токена – дело приложения.
 *
 * С $baseUrl (прокси) все вызовы Graph идут на <baseUrl>/v26.0/… с X-Proxy-Auth.
 */
final class Auth
{
    public const AUTHORIZE_URL = 'https://www.facebook.com/' . BotApi::GRAPH_VERSION . '/dialog/oauth';
    public const GRAPH_BASE_URL = 'https://graph.facebook.com';
    public const ACCOUNTS_PAGE_LIMIT = 100;
    // Клиент выдаёт доступ к считанным аккаунтам: больше 500 – скорее зацикленный курсор.
    public const ACCOUNTS_MAX_PAGES = 5;

    private Client $http;

    public function __construct(
        private readonly string $appId,
        #[\SensitiveParameter] private readonly string $appSecret,
        private readonly string $configId,
        private readonly string $redirectUri,
        ?Client $http = null,
        private readonly string $baseUrl = '',
        #[\SensitiveParameter] private readonly string $proxySecret = '',
        private readonly int $timeout = 30
    ) {
        if (!preg_match('/^[0-9]{1,32}$/D', $appId) || $appSecret === '') {
            throw new \InvalidArgumentException('Invalid Instagram app credentials');
        }
        if (!preg_match('/^[0-9]{1,32}$/D', $configId)) {
            throw new \InvalidArgumentException('Invalid Facebook Login configuration ID');
        }
        // 0 у cURL – «без таймаута».
        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeouts must be positive');
        }
        Config::assertHttpsUrl($redirectUri);
        if ($this->proxied()) {
            Config::assertHttpsUrl($baseUrl);
            if (parse_url($baseUrl, PHP_URL_QUERY) !== null) {
                throw new \InvalidArgumentException('Instagram proxy URL must not contain a query');
            }
            if ($proxySecret === '') {
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

    /** Разрешения задаёт конфигурация входа, scope не передаётся. */
    public function authorizeUrl(string $state): string
    {
        if ($state === '') {
            throw new \InvalidArgumentException('State is required');
        }
        $query = [
            'client_id' => $this->appId,
            'config_id' => $this->configId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'state' => $state,
        ];
        return self::AUTHORIZE_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Бессрочный токен BISU; код одноразовый. */
    public function exchangeCode(#[\SensitiveParameter] string $code): Token
    {
        // Facebook дописывает к адресу возврата фрагмент «#_=_», это не часть кода.
        $code = preg_replace('/#.*$/sD', '', $code);
        if ($code === '') {
            throw new \InvalidArgumentException('Empty authorization code');
        }
        $data = $this->graph('/oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ApiException(200, 'invalid_token_response');
        }
        $expires = $data['expires_in'] ?? 0;
        return new Token($token, is_int($expires) && $expires > 0 ? $expires : 0);
    }

    /** Бизнес-портфолио клиента (client_business_id из /me); '' – Meta его не вернула. */
    public function clientBusinessId(#[\SensitiveParameter] string $token): string
    {
        $data = $this->graph('/me', ['fields' => 'id,client_business_id'], $token);
        $id = $data['client_business_id'] ?? '';
        return (is_string($id) || is_int($id)) && preg_match('/^[0-9]{1,32}$/D', (string) $id)
            ? (string) $id
            : '';
    }

    /** @return list<string> только разрешения со статусом granted */
    public function permissions(#[\SensitiveParameter] string $token): array
    {
        $data = $this->graph('/me/permissions', [], $token);
        $granted = [];
        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $item) {
            if (
                is_array($item) &&
                ($item['status'] ?? null) === 'granted' &&
                is_string($item['permission'] ?? null)
            ) {
                $granted[] = $item['permission'];
            }
        }
        return $granted;
    }

    /**
     * Аккаунты Instagram из выданного доступа. assigned_instagram_accounts бывает пустым и при
     * выданном аккаунте – тогда owned_instagram_accounts бизнеса клиента; отказ в правах на
     * нём (нужен business_management) – «не найдено», а не ошибка. Запасной список, видимо, –
     * все аккаунты портфолио, шире выданного при входе: аккаунт выбирает клиент, не первый.
     * @return list<Account>
     */
    public function accounts(#[\SensitiveParameter] string $token): array
    {
        $accounts = $this->accountList('/me/assigned_instagram_accounts', $token);
        if ($accounts !== []) {
            return $accounts;
        }
        $businessId = $this->clientBusinessId($token);
        return $businessId === ''
            ? []
            : $this->accountList('/' . $businessId . '/owned_instagram_accounts', $token, true);
    }

    public function __debugInfo(): array
    {
        return ['appId' => $this->appId, 'configId' => $this->configId, 'appSecret' => '[redacted]'];
    }

    /** @return list<Account> */
    private function accountList(
        string $path,
        #[\SensitiveParameter] string $token,
        bool $deniedIsEmpty = false
    ): array {
        $accounts = [];
        $after = null;
        for ($page = 0; $page < self::ACCOUNTS_MAX_PAGES; $page++) {
            $query = ['fields' => 'id,username', 'limit' => self::ACCOUNTS_PAGE_LIMIT];
            if ($after !== null) {
                $query['after'] = $after;
            }
            // Отказ в правах – «не найдено» только на первой странице: дальше это обрыв списка.
            $data = $this->graph($path, $query, $token, $deniedIsEmpty && $page === 0);
            if ($data === null) {
                break;
            }
            if (!is_array($data['data'] ?? null)) {
                throw new ApiException(200, 'invalid_page');
            }
            foreach ($data['data'] as $raw) {
                $account = is_array($raw) ? Account::fromArray($raw) : null;
                if ($account !== null) {
                    $accounts[$account->id] = $account;
                }
            }
            // Курсор after бывает и на последней странице: признак продолжения – paging.next.
            $paging = is_array($data['paging'] ?? null) ? $data['paging'] : [];
            $next = $paging['cursors']['after'] ?? null;
            if (!isset($paging['next']) || !is_string($next) || $next === '') {
                break;
            }
            $after = $next;
        }
        return array_values($accounts);
    }

    /**
     * Обмен кода принимает секрет приложения только в query (GET): URL не логируется нигде,
     * ошибки клиента очищаются до безопасной причины. Токен доступа идёт заголовком.
     * null – отказ в правах при $deniedIsEmpty.
     */
    private function graph(
        string $path,
        #[\SensitiveParameter] array $query,
        #[\SensitiveParameter] string $token = '',
        bool $deniedIsEmpty = false
    ): ?array {
        $headers = [];
        if ($token !== '') {
            // Перевод строки или пробел в токене – подмена заголовков.
            if (!preg_match('/^[A-Za-z0-9._|-]+$/D', $token)) {
                throw new ApiException(0, 'invalid_token');
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($this->proxied()) {
            $headers[] = 'X-Proxy-Auth: ' . $this->proxySecret;
        }
        $url =
            ($this->proxied() ? rtrim($this->baseUrl, '/') : self::GRAPH_BASE_URL) .
            '/' .
            BotApi::GRAPH_VERSION .
            $path .
            ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        try {
            $response = $this->http->send(new Request('GET', $url, $headers, null, $this->timeout));
        } catch (\Throwable $e) {
            throw ApiException::fromTransport($e);
        }
        if ($deniedIsEmpty && self::isPermissionError($response)) {
            return null;
        }
        $error = Errors::classify($response);
        if ($error !== null) {
            throw $error;
        }
        return $response->json();
    }

    // Как в BotApi: явный адрес Graph – не прокси, секрет прокси в Meta не уходит.
    private function proxied(): bool
    {
        return $this->baseUrl !== '' && rtrim($this->baseUrl, '/') !== self::GRAPH_BASE_URL;
    }

    // Коды Graph API: 10 и 200–299 – отказ в разрешении.
    private static function isPermissionError(\diBot\Http\Response $response): bool
    {
        try {
            $code = $response->json()['error']['code'] ?? null;
        } catch (ApiException) {
            return false;
        }
        return $code === 10 || (is_int($code) && $code >= 200 && $code <= 299);
    }
}
