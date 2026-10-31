<?php
namespace diBot\Tests;

use diBot\Exception\ApiException;
use diBot\Http\Response;
use diBot\Instagram\{Account, Auth, Token};
use PHPUnit\Framework\TestCase;

final class InstagramAuthTest extends TestCase
{
    private const TOKEN = 'EAAbisuTOKEN123';

    private static function auth(FakeClient $http, string $baseUrl = '', string $proxy = ''): Auth
    {
        return new Auth('1', 'app-secret', '2002', 'https://app.example/cb', $http, $baseUrl, $proxy);
    }

    private static function denied(int $code): Response
    {
        return FakeClient::json(
            ['error' => ['message' => '(#' . $code . ') Requires business_management permission', 'code' => $code]],
            403
        );
    }

    public function testConstructorValidation(): void
    {
        foreach (
            [
                fn() => new Auth('1', 'secret', '', 'https://lk.example/cb'),
                fn() => new Auth('1', 'secret', 'cfg', 'https://lk.example/cb'),
                fn() => new Auth('x', 'secret', '2', 'https://lk.example/cb'),
                fn() => new Auth('1', '', '2', 'https://lk.example/cb'),
                fn() => new Auth('1', 'secret', '2', 'http://lk.example/cb'),
                fn() => new Auth('1', 'secret', '2', 'https://lk.example/cb', timeout: 0),
                fn() => new Auth('1', 'secret', '2', 'https://lk.example/cb', null, 'https://proxy.example'),
            ]
            as $i => $make
        ) {
            try {
                $make();
                self::fail((string) $i);
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectExceptionMessage('must not contain a query');
        new Auth('1', 'secret', '2', 'https://lk.example/cb', null, 'https://proxy.example/?a=1', 'proxy');
    }

    public function testAuthorizeUrlUsesConfigurationWithoutScope(): void
    {
        $auth = new Auth('990602627938098', 'app-secret', '1234567890', 'https://gagabot.example/ig/callback');
        $url = $auth->authorizeUrl('st-1');
        self::assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame(
            [
                'client_id' => '990602627938098',
                'config_id' => '1234567890',
                'redirect_uri' => 'https://gagabot.example/ig/callback',
                'response_type' => 'code',
                'override_default_response_type' => 'true',
                'state' => 'st-1',
            ],
            $q
        );
        parse_str(parse_url($auth->authorizeUrl('st-2', true), PHP_URL_QUERY), $q);
        self::assertSame('login', $q['prompt']);
        self::assertStringNotContainsString('app-secret', $url);
        self::assertStringNotContainsString('app-secret', print_r($auth, true));
        $this->expectException(\InvalidArgumentException::class);
        $auth->authorizeUrl('');
    }

    public function testExchangeCodeGivesNonExpiringToken(): void
    {
        $http = new FakeClient([
            FakeClient::json(['access_token' => 'BISU', 'token_type' => 'bearer']),
            FakeClient::json(['access_token' => 'TMP', 'expires_in' => 3600]),
        ]);
        $auth = self::auth($http);
        $token = $auth->exchangeCode('AQBx#_=_');
        self::assertInstanceOf(Token::class, $token);
        self::assertSame(['BISU', 0], [$token->accessToken, $token->expiresIn]);
        $r = $http->requests[0];
        self::assertSame('GET', $r->method);
        self::assertNull($r->body);
        self::assertStringStartsWith('https://graph.facebook.com/v26.0/oauth/access_token?', $r->url);
        parse_str(parse_url($r->url, PHP_URL_QUERY), $q);
        self::assertSame(
            [
                'client_id' => '1',
                'client_secret' => 'app-secret',
                'redirect_uri' => 'https://app.example/cb',
                'code' => 'AQBx',
            ],
            $q
        );
        self::assertSame([], $r->headers);
        self::assertSame(3600, $auth->exchangeCode('c')->expiresIn);
        self::assertStringNotContainsString('BISU', print_r($token, true));
        $this->expectException(\InvalidArgumentException::class);
        $auth->exchangeCode('#_=_');
    }

    public function testGrantReadsUseBearerHeader(): void
    {
        $http = new FakeClient([
            FakeClient::json(['id' => '122100000000000001', 'client_business_id' => '900000000000001']),
            FakeClient::json([
                'data' => [
                    ['permission' => 'instagram_basic', 'status' => 'granted'],
                    ['permission' => 'instagram_manage_messages', 'status' => 'granted'],
                    ['permission' => 'business_management', 'status' => 'declined'],
                    'broken',
                ],
            ]),
            FakeClient::json(['id' => '122100000000000001']),
        ]);
        $auth = self::auth($http);
        self::assertSame('900000000000001', $auth->clientBusinessId(self::TOKEN));
        self::assertSame(['instagram_basic', 'instagram_manage_messages'], $auth->permissions(self::TOKEN));
        self::assertSame('', $auth->clientBusinessId(self::TOKEN));
        self::assertSame(
            'https://graph.facebook.com/v26.0/me?fields=' . rawurlencode('id,client_business_id'),
            $http->requests[0]->url
        );
        self::assertSame('https://graph.facebook.com/v26.0/me/permissions', $http->requests[1]->url);
        foreach ($http->requests as $r) {
            self::assertSame(['Authorization: Bearer ' . self::TOKEN], $r->headers);
            self::assertStringNotContainsString(self::TOKEN, $r->url);
        }
    }

    public function testAccountsFromAssignedListArePaginatedAndCapped(): void
    {
        $page = static fn(array $ids, ?string $after) => FakeClient::json(
            [
                'data' => array_map(static fn($id) => ['id' => $id, 'username' => 'u' . $id], $ids),
            ] + ($after === null ? [] : ['paging' => ['cursors' => ['after' => $after], 'next' => 'https://…']])
        );
        $http = new FakeClient([
            $page(['17841400000000001', '17841400000000002'], 'C1'),
            $page(['17841400000000002', 'bad'], null),
        ]);
        $accounts = self::auth($http)->accounts(self::TOKEN);
        self::assertEquals(
            [new Account('17841400000000001', 'u17841400000000001'), new Account('17841400000000002', 'u17841400000000002')],
            $accounts
        );
        self::assertCount(2, $http->requests);
        parse_str(parse_url($http->requests[0]->url, PHP_URL_QUERY), $q);
        self::assertSame(['fields' => 'id,username', 'limit' => '100'], $q);
        self::assertStringStartsWith(
            'https://graph.facebook.com/v26.0/me/assigned_instagram_accounts?',
            $http->requests[0]->url
        );
        self::assertStringContainsString('after=C1', $http->requests[1]->url);
        // Бесконечный курсор не крутится дальше ACCOUNTS_MAX_PAGES.
        $responses = [];
        for ($i = 0; $i < Auth::ACCOUNTS_MAX_PAGES + 2; $i++) {
            $responses[] = $page(['1784140000000000' . $i], 'C');
        }
        $http = new FakeClient($responses);
        self::assertCount(Auth::ACCOUNTS_MAX_PAGES, self::auth($http)->accounts(self::TOKEN));
        self::assertCount(Auth::ACCOUNTS_MAX_PAGES, $http->requests);
    }

    public function testAccountsFallBackToOwnedAccountsOfClientBusiness(): void
    {
        $http = new FakeClient([
            FakeClient::json(['data' => []]),
            FakeClient::json(['id' => '1', 'client_business_id' => '900000000000001']),
            FakeClient::json(['data' => [['id' => '17841400000000003', 'username' => 'shop']]]),
        ]);
        self::assertEquals([new Account('17841400000000003', 'shop')], self::auth($http)->accounts(self::TOKEN));
        self::assertStringStartsWith(
            'https://graph.facebook.com/v26.0/900000000000001/owned_instagram_accounts?fields=',
            $http->requests[2]->url
        );
        // Нет client_business_id – запасного запроса нет.
        $http = new FakeClient([FakeClient::json(['data' => []]), FakeClient::json(['id' => '1'])]);
        self::assertSame([], self::auth($http)->accounts(self::TOKEN));
        self::assertCount(2, $http->requests);
    }

    public function testPermissionErrorOnFallbackMeansNothingFound(): void
    {
        foreach ([200, 10, 299] as $code) {
            $http = new FakeClient([
                FakeClient::json(['data' => []]),
                FakeClient::json(['client_business_id' => '900000000000001']),
                self::denied($code),
            ]);
            self::assertSame([], self::auth($http)->accounts(self::TOKEN), (string) $code);
        }
        // Прочие ошибки запасного запроса и любая ошибка основного – не «пусто».
        foreach (
            [
                [[FakeClient::json(['data' => []]), FakeClient::json(['client_business_id' => '9']), self::denied(190)], 'invalid_token'],
                [[FakeClient::json(['data' => []]), FakeClient::json(['client_business_id' => '9']), self::denied(4)], 'rate_limit'],
                [[self::denied(200)], 'api_error'],
                [[FakeClient::json(['oops' => 1])], 'invalid_page'],
            ]
            as [$responses, $reason]
        ) {
            try {
                self::auth(new FakeClient($responses))->accounts(self::TOKEN);
                self::fail($reason);
            } catch (ApiException $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('business_management', $e->getMessage());
            }
        }
    }

    public function testProxyRoutingAndHeaders(): void
    {
        $http = new FakeClient([
            FakeClient::json(['access_token' => 'BISU']),
            FakeClient::json(['data' => []]),
        ]);
        $auth = self::auth($http, 'https://ig-api.example/', 'px');
        $auth->exchangeCode('c');
        $auth->permissions(self::TOKEN);
        self::assertStringStartsWith('https://ig-api.example/v26.0/oauth/access_token?', $http->requests[0]->url);
        self::assertSame('https://ig-api.example/v26.0/me/permissions', $http->requests[1]->url);
        self::assertSame(['X-Proxy-Auth: px'], $http->requests[0]->headers);
        self::assertSame(['Authorization: Bearer ' . self::TOKEN, 'X-Proxy-Auth: px'], $http->requests[1]->headers);
        self::assertStringNotContainsString('px', print_r($auth, true));
    }

    public function testSecretsNeverReachExceptions(): void
    {
        $http = new FakeClient([
            FakeClient::json(['error' => ['code' => 190, 'message' => 'code c expired, secret app-secret']], 400),
            new \RuntimeException('https://graph.facebook.com/v26.0/oauth/access_token?client_secret=app-secret'),
            FakeClient::json(['token_type' => 'bearer']),
        ]);
        $auth = self::auth($http);
        foreach (['invalid_token', 'network_error', 'invalid_token_response'] as $reason) {
            try {
                $auth->exchangeCode('c');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('app-secret', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
        // Пробел или перевод строки в токене – подмена заголовков: до сети не доходит.
        try {
            $auth->permissions("a\r\nX-Evil: 1");
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('invalid_token', $e->reason);
        }
        self::assertCount(3, $http->requests);
    }
}
