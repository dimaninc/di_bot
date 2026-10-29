<?php
namespace diBot\Tests;

use diBot\{Button, Config, Keyboard, Platform};
use diBot\Exception\{ApiException, DownloadException};
use diBot\Http\Response;
use diBot\Instagram\{Auth, BotApi, Comment, MediaItem, UserProfile};
use diBot\Outgoing\{Media, Policy, Sender, Transport};
use PHPUnit\Framework\TestCase;

final class InstagramApiTest extends TestCase
{
    private const TOKEN = 'IGAAsecretTOKEN123';
    private array $logs = [];

    private function api(array $responses, string $baseUrl = '', string $proxy = ''): array
    {
        $http = new FakeClient($responses);
        $logger = new class ($this->logs) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$logs)
            {
            }
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = $message . ' ' . json_encode($context);
            }
        };
        $config = new Config(self::TOKEN, $baseUrl, proxySecret: $proxy);
        return [Platform::Instagram->api($config, $http, $logger), $http];
    }

    private static function sent(): Response
    {
        return FakeClient::json(['recipient_id' => '5', 'message_id' => 'mid']);
    }

    private static function body(FakeClient $http, int $i = 0): array
    {
        return json_decode($http->requests[$i]->body, true);
    }

    public function testSendMessageShapeAuthAndUrl(): void
    {
        [$api, $http] = $this->api([self::sent()]);
        self::assertInstanceOf(BotApi::class, $api);
        self::assertSame(Platform::Instagram, $api->platform());
        self::assertSame(['recipient_id' => '5', 'message_id' => 'mid'], $api->sendMessage('5', 'Привет'));
        $r = $http->requests[0];
        self::assertSame('POST', $r->method);
        self::assertSame('https://graph.instagram.com/v26.0/me/messages', $r->url);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $r->headers);
        self::assertStringNotContainsString('X-Proxy-Auth', implode("\n", $r->headers));
        self::assertSame(
            ['recipient' => ['id' => '5'], 'message' => ['text' => 'Привет']],
            self::body($http)
        );
    }

    public function testProxyBaseUrlAndHeader(): void
    {
        [$api, $http] = $this->api([self::sent()], 'https://ig-api.example', 'proxy-secret');
        $api->sendMessage('5', 'x');
        self::assertSame('https://ig-api.example/v26.0/me/messages', $http->requests[0]->url);
        self::assertContains('X-Proxy-Auth: proxy-secret', $http->requests[0]->headers);
        [$api] = $this->api([], 'https://ig-api.example');
        $this->expectException(\InvalidArgumentException::class);
        $api->sendMessage('5', 'x');
    }

    public function testKeyboardUpToThreeButtonsIsButtonTemplate(): void
    {
        [$api, $http] = $this->api([self::sent()]);
        $api->sendMessage(
            '5',
            'Выберите',
            new Keyboard([
                [Button::callback('Очень длинная подпись кнопки', 'a:1')],
                [Button::link('Сайт', 'https://example.com')],
            ])
        );
        self::assertSame(
            [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text' => 'Выберите',
                        'buttons' => [
                            ['type' => 'postback', 'title' => 'Очень длинная подпис', 'payload' => 'a:1'],
                            ['type' => 'web_url', 'url' => 'https://example.com', 'title' => 'Сайт'],
                        ],
                    ],
                ],
            ],
            self::body($http)['message']
        );
    }

    public function testMoreThanThreeCallbacksAreQuickReplies(): void
    {
        [$api, $http] = $this->api([self::sent()]);
        $buttons = [];
        for ($i = 1; $i <= 13; $i++) {
            $buttons[] = Button::callback((string) $i, 'q:' . $i);
        }
        $api->sendMessage('5', 'Вопрос', new Keyboard(array_chunk($buttons, 4)));
        $message = self::body($http)['message'];
        self::assertSame('Вопрос', $message['text']);
        self::assertCount(13, $message['quick_replies']);
        self::assertSame(
            ['content_type' => 'text', 'title' => '13', 'payload' => 'q:13'],
            $message['quick_replies'][12]
        );
        // Пустая клавиатура – обычный текст.
        [$api, $http] = $this->api([self::sent()]);
        $api->sendMessage('5', 'x', new Keyboard());
        self::assertSame(['text' => 'x'], self::body($http)['message']);
    }

    public function testInvalidKeyboardsAreRejectedBeforeNetwork(): void
    {
        $link = Button::link('L', 'https://example.com');
        $cb = Button::callback('C', 'c');
        foreach (
            [
                [[$cb, $cb, $cb, $link]],
                [array_fill(0, 14, $cb)],
                [[Button::callback('C', str_repeat('x', 1001))]],
            ]
            as $rows
        ) {
            [$api, $http] = $this->api([]);
            try {
                $api->sendMessage('5', 'x', new Keyboard($rows));
                self::fail();
            } catch (\InvalidArgumentException) {
                self::assertSame([], $http->requests);
            }
        }
        // Шаблон: текст до 640 символов.
        [$api, $http] = $this->api([]);
        try {
            $api->sendMessage('5', str_repeat('a', 641), new Keyboard([[$cb]]));
            self::fail();
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testTextLimitIsCountedInUtf8Bytes(): void
    {
        [$api, $http] = $this->api([self::sent()]);
        $api->sendMessage('5', str_repeat('ж', 500));
        self::assertCount(1, $http->requests);
        foreach ([str_repeat('ж', 501), str_repeat('a', 1001), "\xff"] as $text) {
            try {
                $api->sendMessage('5', $text);
                self::fail();
            } catch (\InvalidArgumentException) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function testPrivateReplyAndPublicReply(): void
    {
        [$api, $http] = $this->api([self::sent(), FakeClient::json(['id' => '17873440459141029'])]);
        $api->sendPrivateReply(
            '17900000000000001',
            'Привет из лички',
            new Keyboard([[Button::callback('Готово', 'done')]])
        );
        $body = self::body($http);
        self::assertSame(['comment_id' => '17900000000000001'], $body['recipient']);
        self::assertSame('button', $body['message']['attachment']['payload']['template_type']);
        self::assertSame(['id' => '17873440459141029'], $api->replyToComment('17900000000000001', 'Ответили в личку'));
        self::assertSame(
            'https://graph.instagram.com/v26.0/17900000000000001/replies',
            $http->requests[1]->url
        );
        self::assertSame(['message' => 'Ответили в личку'], self::body($http, 1));
        foreach (['../me', '1/2', ''] as $id) {
            try {
                $api->replyToComment($id, 'x');
                self::fail();
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(2, $http->requests);
    }

    public function testGetCommentsRequestAndNormalization(): void
    {
        [$api, $http] = $this->api([
            FakeClient::json([
                'data' => [
                    [
                        'id' => '17900000000000001',
                        'text' => 'хочу',
                        'timestamp' => '2026-10-01T10:00:00+0000',
                        'from' => ['id' => '555', 'username' => 'client'],
                        'media' => ['id' => '18000000000000001'],
                    ],
                    ['text' => 'без id'],
                    'broken',
                ],
                'paging' => ['cursors' => ['after' => 'QVFIUa'], 'next' => 'https://graph…'],
            ]),
            FakeClient::json(['data' => [], 'paging' => ['cursors' => ['after' => 'QVFIUb']]]),
        ]);
        $page = $api->getComments('18000000000000001', 'QVFIU0', 50);
        $url = $http->requests[0]->url;
        self::assertStringStartsWith(
            'https://graph.instagram.com/v26.0/18000000000000001/comments?fields=',
            $url
        );
        self::assertStringContainsString('limit=50', $url);
        self::assertStringContainsString('after=QVFIU0', $url);
        self::assertStringNotContainsString(self::TOKEN, $url);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertCount(1, $page->items);
        $c = $page->items[0];
        self::assertInstanceOf(Comment::class, $c);
        self::assertSame(
            ['17900000000000001', '18000000000000001', '', 'хочу', '555', 'client'],
            [$c->id, $c->mediaId, $c->parentId, $c->text, $c->fromId, $c->fromUsername]
        );
        self::assertSame('2026-10-01T10:00:00+0000', $c->timestamp);
        self::assertSame('QVFIUa', $page->next);
        // Без paging.next страниц больше нет, даже если курсор after есть.
        self::assertNull($api->getComments('18000000000000001')->next);
        foreach ([[0], [51], ['x', 'bad cursor!']] as $args) {
            try {
                count($args) === 1
                    ? $api->getComments('1', null, $args[0])
                    : $api->getComments('1', $args[1]);
                self::fail();
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testGetMedia(): void
    {
        [$api, $http] = $this->api([
            FakeClient::json([
                'data' => [
                    [
                        'id' => '18000000000000001',
                        'caption' => 'Пост',
                        'media_type' => 'IMAGE',
                        'permalink' => 'https://www.instagram.com/p/x/',
                        'media_url' => 'https://scontent.cdninstagram.com/x.jpg',
                        'timestamp' => '2026-10-01T10:00:00+0000',
                    ],
                ],
            ]),
        ]);
        $page = $api->getMedia(limit: 10);
        self::assertStringStartsWith(
            'https://graph.instagram.com/v26.0/me/media?fields=',
            $http->requests[0]->url
        );
        self::assertInstanceOf(MediaItem::class, $page->items[0]);
        self::assertSame('Пост', $page->items[0]->caption);
        self::assertSame('IMAGE', $page->items[0]->mediaType);
        self::assertSame('', $page->items[0]->thumbnailUrl);
        self::assertNull($page->next);
        self::assertStringNotContainsString('Пост', print_r($page->items[0], true));
    }

    public function testGetUserProfile(): void
    {
        [$api, $http] = $this->api([
            FakeClient::json([
                'name' => 'Peter',
                'username' => 'peter',
                'follower_count' => 1234,
                'is_user_follow_business' => false,
                'is_business_follow_user' => true,
            ]),
            FakeClient::json(['username' => 'x']),
        ]);
        $p = $api->getUserProfile('1234567890123456');
        self::assertInstanceOf(UserProfile::class, $p);
        self::assertStringStartsWith(
            'https://graph.instagram.com/v26.0/1234567890123456?fields=',
            $http->requests[0]->url
        );
        self::assertStringContainsString('is_user_follow_business', urldecode($http->requests[0]->url));
        self::assertSame(['1234567890123456', 'peter', 'Peter'], [$p->id, $p->username, $p->name]);
        self::assertFalse($p->followsBusiness);
        self::assertTrue($p->businessFollowsUser);
        self::assertFalse($p->isVerified);
        self::assertSame(1234, $p->followerCount);
        // Поля не пришли – «неизвестно», а не «не подписан».
        $unknown = $api->getUserProfile('1');
        self::assertNull($unknown->followsBusiness);
        self::assertNull($unknown->followerCount);
    }

    public function testGetMeExposesWebhookAccountId(): void
    {
        foreach (
            [
                ['user_id' => '17841400000000001', 'username' => 'shop', 'id' => '2600000000000001'],
                ['data' => [['user_id' => '17841400000000001', 'username' => 'shop', 'id' => '2600000000000001']]],
            ]
            as $response
        ) {
            [$api, $http] = $this->api([FakeClient::json($response)]);
            self::assertSame(
                [
                    'account_id' => '17841400000000001',
                    'username' => 'shop',
                    'app_scoped_id' => '2600000000000001',
                ],
                $api->getMe()
            );
            self::assertStringContainsString('fields=user_id', $http->requests[0]->url);
        }
        [$api] = $this->api([FakeClient::json(['id' => '1'])]);
        $this->expectException(ApiException::class);
        $api->getMe();
    }

    public function testWebhookSubscriptionOfTheAccount(): void
    {
        [$api, $http] = $this->api([
            FakeClient::json(['success' => true]),
            FakeClient::json(['success' => true]),
        ]);
        self::assertSame(['success' => true], $api->setWebhook('https://ignored.example/hook'));
        $r = $http->requests[0];
        self::assertSame('POST', $r->method);
        self::assertSame(
            'https://graph.instagram.com/v26.0/me/subscribed_apps?subscribed_fields=' .
                rawurlencode('comments,live_comments,messages,messaging_postbacks'),
            $r->url
        );
        self::assertStringNotContainsString('ignored', $r->url . $r->body);
        $api->deleteWebhook('https://ignored.example/hook');
        self::assertSame('DELETE', $http->requests[1]->method);
        self::assertSame(
            'https://graph.instagram.com/v26.0/me/subscribed_apps',
            $http->requests[1]->url
        );
    }

    public function testUnsupportedMethodsAndLocalCallbackAnswer(): void
    {
        [$api, $http] = $this->api([]);
        foreach (
            [
                fn() => $api->editMessage('5', 'm', 'x'),
                fn() => $api->setCommands([]),
                fn() => $api->getUpdates(),
                fn() => $api->sendFileId('5', 'photo', 'id'),
            ]
            as $call
        ) {
            try {
                $call();
                self::fail();
            } catch (ApiException $e) {
                self::assertSame('unsupported', $e->reason);
            }
        }
        self::assertSame(['ok' => true], $api->answerCallback('pb-1', 'Готово'));
        self::assertSame([], $http->requests);
    }

    public function testErrorClassification(): void
    {
        $error = static fn(int $code, ?int $subcode = null, string $message = 'Secret text 123') =>
            FakeClient::json(
                ['error' => array_filter([
                    'message' => $message,
                    'type' => 'OAuthException',
                    'code' => $code,
                    'error_subcode' => $subcode,
                    'fbtrace_id' => 'trace',
                ])],
                400
            );
        foreach (
            [
                [$error(10, 2018278), 'window_closed', false, 0],
                [$error(10, 2534022), 'window_closed', false, 0],
                [$error(100, 2534025), 'reply_unavailable', false, 0],
                [$error(100, null, 'User consent is required to access user profile'), 'consent_required', false, 0],
                [$error(190), 'invalid_token', false, 0],
                [$error(551), 'api_error', true, 0],
                [$error(10, 2018108), 'api_error', true, 0],
                [$error(4), 'rate_limit', false, 60],
                [$error(613, 2534040), 'rate_limit', false, 60],
                [$error(613, 2018338), 'api_error', false, 0],
                [$error(80002), 'rate_limit', false, 60],
                [$error(100, 2534014), 'api_error', false, 0],
                [new Response(429, 'not json'), 'rate_limit', false, 60],
                [
                    new Response(400, json_encode(['error' => ['code' => 17]]), [
                        'x-business-use-case-usage' => json_encode([
                            '1784' => [['type' => 'instagram', 'estimated_time_to_regain_access' => 5]],
                        ]),
                    ]),
                    'rate_limit',
                    false,
                    300,
                ],
                [new Response(500, json_encode(['error' => ['code' => 2]])), 'api_error', false, 0],
            ]
            as $i => [$response, $reason, $blocked, $delay]
        ) {
            [$api] = $this->api([$response]);
            try {
                $api->sendMessage('5', 'x');
                self::fail((string) $i);
            } catch (ApiException $e) {
                self::assertSame($reason, $e->reason, (string) $i);
                self::assertSame($blocked, $e->blocked, (string) $i);
                self::assertSame($delay, $e->rateLimitDelay(), (string) $i);
                self::assertStringNotContainsString('Secret text', $e->getMessage());
                self::assertStringNotContainsString('is required', $e->getMessage());
            }
        }
        [$api] = $this->api([new Response(500, json_encode(['error' => ['code' => 2]]))]);
        try {
            $api->sendMessage('5', 'x');
        } catch (ApiException $e) {
            self::assertTrue($e->isDeliveryUncertain());
        }
        self::assertSame(60, $api->rateLimitDelay(['error' => ['code' => 32]]));
        self::assertSame(0, $api->rateLimitDelay(['error' => ['code' => 100]], 400));
    }

    public function testTokenNeverInUrlLogsOrExceptions(): void
    {
        [$api, $http] = $this->api([
            FakeClient::json(['error' => ['code' => 190, 'message' => 'bad ' . self::TOKEN]], 401),
            new \RuntimeException('curl https://x?access_token=' . self::TOKEN),
        ]);
        foreach ([1, 2] as $_) {
            try {
                $api->sendMessage('5', 'секретный текст');
                self::fail();
            } catch (ApiException $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
        foreach ($http->requests as $r) {
            self::assertStringNotContainsString(self::TOKEN, $r->url);
        }
        self::assertNotEmpty($this->logs);
        $logs = implode("\n", $this->logs);
        self::assertStringNotContainsString(self::TOKEN, $logs);
        self::assertStringNotContainsString('секретный', $logs);
        self::assertStringNotContainsString('bad', $logs);
        // Перевод строки в токене не должен дойти до заголовка.
        $api = new BotApi(new Config('a|b.c'), $http = new FakeClient([self::sent()]));
        $api->sendMessage('5', 'x');
        self::assertCount(1, $http->requests);
    }

    public function testMediaGoesByUrlOnlyAndWithoutCaption(): void
    {
        [$api, $http] = $this->api([self::sent(), self::sent()]);
        $photo = new Media('photo', 'image/jpeg', 1000, 'https://files.example/a.jpg');
        $pdf = new Media('document', 'application/pdf', 20 * 1024 * 1024, 'https://files.example/a.pdf');
        self::assertSame(Transport::Url, Policy::decide($photo, $api)->transport);
        self::assertSame(Transport::Url, Policy::decide($pdf, $api)->transport);
        $api->sendMedia('5', $photo, Transport::Url);
        $api->sendMedia('5', $pdf, Transport::Url);
        self::assertSame(
            ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://files.example/a.jpg']]]],
            self::body($http, 0)['message']
        );
        self::assertSame(
            ['attachment' => ['type' => 'file', 'payload' => ['url' => 'https://files.example/a.pdf']]],
            self::body($http, 1)['message']
        );
        foreach (
            [
                [new Media('photo', 'image/png', 9 * 1024 * 1024, 'https://f.example/a'), 'too_large'],
                [new Media('document', 'application/pdf', 26 * 1024 * 1024, 'https://f.example/a'), 'too_large'],
                [new Media('document', 'application/zip', 1, 'https://f.example/a'), 'unsupported_type'],
                [new Media('photo', 'image/webp', 1, 'https://f.example/a'), 'unsupported_type'],
                [new Media('document', 'application/pdf', 1, localPath: '/tmp/a.pdf'), 'no_public_url'],
                [$photo->withCaption('подпись'), 'caption_too_long'],
            ]
            as [$media, $reason]
        ) {
            $decision = Policy::decide($media, $api);
            self::assertSame(Transport::Refuse, $decision->transport);
            self::assertSame($reason, $decision->reason);
        }
        foreach (
            [
                fn() => $api->sendMedia('5', $photo, Transport::Upload),
                fn() => $api->sendMedia('5', $photo->withCaption('x'), Transport::Url),
                fn() => $api->sendMedia('5', $photo, Transport::Url, new Keyboard([[Button::callback('a', 'b')]])),
            ]
            as $call
        ) {
            try {
                $call();
                self::fail();
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(2, $http->requests);
    }

    public function testSenderSendsTextInsteadOfCaptionedMedia(): void
    {
        [$api, $http] = $this->api([self::sent(), self::sent()]);
        $photo = fn() => new Media('photo', 'image/jpeg', 1, 'https://files.example/a.jpg');
        (new Sender($api))->send('5', 'Итог', $photo, fallbackText: 'короткий');
        // caption_too_long: уходит исходный текст, без медиа.
        self::assertSame(['text' => 'Итог'], self::body($http)['message']);
        (new Sender($api))->send('5', '', $photo);
        self::assertSame('image', self::body($http, 1)['message']['attachments'][0]['type']);
    }

    public function testDownloadUsesPublicCdnUrl(): void
    {
        [$api, $http] = $this->api([new Response(200, 'img', ['content-type' => 'image/jpeg'])]);
        $file = $api->downloadAttachment('', 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1');
        self::assertSame('img', $file->bytes);
        self::assertTrue($http->requests[0]->publicDownload);
        self::assertSame([], $http->requests[0]->headers);
        foreach (['', 'https://127.0.0.1/x'] as $url) {
            try {
                $api->downloadAttachment('', $url);
                self::fail();
            } catch (DownloadException $e) {
                self::assertTrue($e->permanent);
            }
        }
    }

    public function testControllerRefusesInstagramPollingAndWebhook(): void
    {
        [$api, $http] = $this->api([]);
        $controller = new TestController($api);
        $store = new MemoryCursor();
        try {
            $controller->poll($store, 1);
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('unsupported', $e->reason);
        }
        // Ни отписки аккаунта, ни записи позиции.
        self::assertSame([], $http->requests);
        self::assertSame([], $store->saves);
        self::assertSame(['ok' => true], $controller->webhook('{"object":"instagram"}', ''));
        self::assertSame([], $controller->handled);
    }

    public function testAuthorizeUrl(): void
    {
        $auth = new Auth('990602627938098', 'app-secret', 'https://gagabot.example/ig/callback');
        $url = $auth->authorizeUrl('st-1', [
            'instagram_business_basic',
            'instagram_business_manage_messages',
        ]);
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame(
            [
                'client_id' => '990602627938098',
                'redirect_uri' => 'https://gagabot.example/ig/callback',
                'response_type' => 'code',
                'scope' => 'instagram_business_basic,instagram_business_manage_messages',
                'state' => 'st-1',
            ],
            $q
        );
        self::assertStringNotContainsString('app-secret', $url);
        self::assertStringNotContainsString('app-secret', print_r($auth, true));
        $this->expectException(\InvalidArgumentException::class);
        $auth->authorizeUrl('s', ['bad scope']);
    }

    public function testAuthFlowDirect(): void
    {
        $http = new FakeClient([
            FakeClient::json([
                'data' => [
                    [
                        'access_token' => 'SHORT',
                        'user_id' => '1020',
                        'permissions' => 'instagram_business_basic,instagram_business_manage_messages',
                    ],
                ],
            ]),
            FakeClient::json(['access_token' => 'LONG', 'token_type' => 'bearer', 'expires_in' => 5184000]),
            FakeClient::json(['access_token' => 'NEWER', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            FakeClient::json(['access_token' => 'FLAT', 'user_id' => 7, 'permissions' => ['a']]),
        ]);
        $auth = new Auth('1', 'app-secret', 'https://app.example/cb', $http);
        $short = $auth->exchangeCode('AQBx#_');
        self::assertSame(['SHORT', '1020', 0], [$short->accessToken, $short->userId, $short->expiresIn]);
        self::assertSame(
            ['instagram_business_basic', 'instagram_business_manage_messages'],
            $short->permissions
        );
        $r = $http->requests[0];
        self::assertSame('POST', $r->method);
        self::assertSame('https://api.instagram.com/oauth/access_token', $r->url);
        parse_str($r->body, $form);
        self::assertSame(
            [
                'client_id' => '1',
                'client_secret' => 'app-secret',
                'grant_type' => 'authorization_code',
                'redirect_uri' => 'https://app.example/cb',
                'code' => 'AQBx',
            ],
            $form
        );
        $long = $auth->longLived('SHORT');
        self::assertSame(['LONG', 5184000], [$long->accessToken, $long->expiresIn]);
        self::assertSame('GET', $http->requests[1]->method);
        self::assertSame(
            'https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=app-secret&access_token=SHORT',
            $http->requests[1]->url
        );
        $newer = $auth->refresh('LONG');
        self::assertSame('NEWER', $newer->accessToken);
        self::assertSame(
            'https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=LONG',
            $http->requests[2]->url
        );
        $flat = $auth->exchangeCode('c');
        self::assertSame(['FLAT', '7', ['a']], [$flat->accessToken, $flat->userId, $flat->permissions]);
        self::assertStringNotContainsString('LONG', print_r($long, true));
        foreach ($http->requests as $request) {
            self::assertStringNotContainsString('X-Proxy-Auth', implode("\n", $request->headers));
        }
    }

    public function testAuthProxyRoutingAndErrors(): void
    {
        $http = new FakeClient([
            FakeClient::json(['access_token' => 'S', 'user_id' => '1']),
            FakeClient::json(['access_token' => 'L', 'expires_in' => 1]),
            FakeClient::json(['access_token' => 'R', 'expires_in' => 1]),
            FakeClient::json(['error' => ['code' => 190, 'message' => 'token LONG expired']], 400),
            new \RuntimeException('https://graph.instagram.com/access_token?client_secret=app-secret'),
            FakeClient::json(['token_type' => 'bearer']),
        ]);
        $auth = new Auth('1', 'app-secret', 'https://app.example/cb', $http, 'https://ig-api.example/', 'px');
        $auth->exchangeCode('c');
        $auth->longLived('S');
        $auth->refresh('L');
        self::assertSame('https://ig-api.example/oauth/access_token', $http->requests[0]->url);
        self::assertStringStartsWith('https://ig-api.example/access_token?', $http->requests[1]->url);
        self::assertStringStartsWith('https://ig-api.example/refresh_access_token?', $http->requests[2]->url);
        foreach ($http->requests as $request) {
            self::assertContains('X-Proxy-Auth: px', $request->headers);
        }
        foreach (['invalid_token', 'network_error', 'invalid_token_response'] as $reason) {
            try {
                $auth->refresh('LONG');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('LONG', $e->getMessage());
                self::assertStringNotContainsString('app-secret', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new Auth('1', 'app-secret', 'https://app.example/cb', $http, 'https://ig-api.example');
    }
}
