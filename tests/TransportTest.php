<?php
namespace diBot\Tests;

use diBot\{Button, Keyboard, Config};
use diBot\Http\Response;
use diBot\Exception\ApiException;
use diBot\Telegram\BotApi as Telegram;
use diBot\Max\BotApi as Max;
use diBot\Outgoing\{Media, Transport};
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private string $file;
    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'di-bot');
        file_put_contents($this->file, "pdf\0data");
    }
    protected function tearDown(): void
    {
        unlink($this->file);
    }
    private function media(string $kind = 'document'): Media
    {
        return new Media(
            $kind,
            'application/pdf',
            8,
            localPath: $this->file,
            filename: "f\"\r\n.pdf",
            caption: 'Тест'
        );
    }

    public function testTelegramJsonAndProxyHeaders(): void
    {
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => ['message_id' => 1]])]);
        $api = new Telegram(
            new Config('123:secret', 'https://proxy.example', proxySecret: 'proxy-secret'),
            $http
        );
        $api->sendMessage('42', 'hello', new Keyboard([[Button::callback('Go', 'go')]]));
        $r = $http->requests[0];
        $body = json_decode($r->body, true);
        self::assertSame('https://proxy.example/bot123:secret/sendMessage', $r->url);
        self::assertContains('X-Proxy-Auth: proxy-secret', $r->headers);
        self::assertSame('go', $body['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        self::assertContains('Content-Length: ' . strlen($r->body), $r->headers);
    }
    public function testTelegramMultipartIsBufferedAndHeadersAreSafe(): void
    {
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => []])]);
        $api = new Telegram(new Config('123:secret'), $http);
        $api->sendMedia('42', $this->media(), Transport::Upload);
        $r = $http->requests[0];
        self::assertIsString($r->body);
        self::assertContains('Content-Length: ' . strlen($r->body), $r->headers);
        self::assertStringContainsString("pdf\0data", $r->body);
        self::assertStringContainsString('filename="f___.pdf"', $r->body);
        self::assertStringNotContainsString('X-Proxy-Auth', implode('\n', $r->headers));
    }
    public function testMaxThreeLegsDoNotSendAuthOrCaToStorage(): void
    {
        $http = new FakeClient([
            FakeClient::json(['url' => 'https://fu.oneme.ru/upload', 'token' => 'file-token']),
            new Response(204, ''),
            FakeClient::json(['message' => ['body' => ['mid' => 'sent']]]),
        ]);
        $api = new Max(new Config('max-secret'), $http);
        $api->sendMedia('42', $this->media(), Transport::Upload);
        [$start, $upload, $send] = $http->requests;
        self::assertContains('Authorization: max-secret', $start->headers);
        self::assertSame(Max::defaultCaBundle(), $start->caBundle);
        self::assertStringNotContainsString('max-secret', implode('\n', $upload->headers));
        self::assertNull($upload->caBundle);
        self::assertTrue($upload->publicUpload);
        self::assertSame(
            'file-token',
            json_decode($send->body, true)['attachments'][0]['payload']['token']
        );
    }
    public function testMaxReadsNestedImageToken(): void
    {
        $http = new FakeClient([
            FakeClient::json(['url' => 'https://iu.oneme.ru/upload']),
            FakeClient::json(['photos' => ['id' => ['token' => 'photo-token']]]),
            FakeClient::json(['message' => []]),
        ]);
        (new Max(new Config('token'), $http))->sendMedia(
            '1',
            $this->media('photo'),
            Transport::Upload
        );
        self::assertSame(
            'photo-token',
            json_decode($http->requests[2]->body, true)['attachments'][0]['payload']['token']
        );
    }
    public function testUploadHttpFailureDoesNotSendEndpointToken(): void
    {
        $http = new FakeClient([
            FakeClient::json(['url' => 'https://fu.oneme.ru/upload', 'token' => 'token']),
            new Response(500, ''),
        ]);
        try {
            (new Max(new Config('token'), $http))->sendMedia(
                '1',
                $this->media(),
                Transport::Upload
            );
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('upload_failed', $e->reason);
            self::assertCount(2, $http->requests);
        }
    }
    public function testMaxRetryOnlyWhenExplicitlyEnabledInCli(): void
    {
        $http = new FakeClient([
            FakeClient::json(['code' => 'attachment.not.ready'], 400),
            FakeClient::json(['message' => []]),
        ]);
        $api = new class (new Config('token', retryMediaInCli: true), $http) extends Max {
            public array $pauses = [];
            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
        $api->sendFileId('1', 'document', 'file-token');
        self::assertSame([2], $api->pauses);
        $http = new FakeClient([FakeClient::json(['code' => 'attachment.not.ready'], 400)]);
        $this->expectException(ApiException::class);
        (new Max(new Config('token'), $http))->sendFileId('1', 'document', 'file-token');
    }
    public function testTelegramErrorsAndHttpRedirectDoNotLeakResponse(): void
    {
        $http = new FakeClient([
            FakeClient::json(
                [
                    'ok' => false,
                    'error_code' => 403,
                    'description' => 'blocked by user token SECRET',
                ],
                403
            ),
        ]);
        try {
            (new Telegram(new Config('123:secret'), $http))->getMe();
            self::fail();
        } catch (ApiException $e) {
            self::assertTrue($e->blocked);
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
        $this->expectException(ApiException::class);
        (new Telegram(
            new Config('123:secret'),
            new FakeClient([FakeClient::json(['ok' => true], 302)])
        ))->getMe();
    }
    public function testMaxErrorFlagAndMalformedJsonAreFailures(): void
    {
        foreach (
            [
                FakeClient::json(['success' => false]),
                new Response(200, 'not json'),
                FakeClient::json(['code' => 'auth', 'message' => 'SECRET']),
            ]
            as $response
        ) {
            try {
                (new Max(new Config('secret'), new FakeClient([$response])))->getMe();
                self::fail();
            } catch (ApiException $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
    }
    public function testMaxDeleteUsesEachSubscriptionUrl(): void
    {
        $http = new FakeClient([
            FakeClient::json([
                'subscriptions' => [
                    ['url' => 'https://example.com/one'],
                    ['url' => 'https://example.com/two'],
                ],
            ]),
            FakeClient::json(['success' => true]),
            FakeClient::json(['success' => true]),
        ]);
        (new Max(new Config('token'), $http))->deleteWebhook();
        self::assertSame('DELETE', $http->requests[1]->method);
        self::assertStringContainsString(
            'url=https%3A%2F%2Fexample.com%2Fone',
            $http->requests[1]->url
        );
        self::assertCount(3, $http->requests);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertStringContainsString('two', $http->requests[2]->url);
    }
    public function testMaxDeleteOwnSubscriptionOnly(): void
    {
        $own = 'https://example.com/hook?bot=1';
        $list = FakeClient::json([
            'subscriptions' => [['url' => 'https://other.example/hook'], ['url' => $own]],
        ]);
        $http = new FakeClient([$list, FakeClient::json(['success' => true])]);
        (new Max(new Config('token'), $http))->deleteWebhook($own);
        self::assertCount(2, $http->requests, 'сверка и снятие своей – два запроса');
        self::assertSame('GET', $http->requests[0]->method);
        self::assertSame('DELETE', $http->requests[1]->method);
        self::assertNull($http->requests[1]->body);
        $query = [];
        parse_str((string) parse_url($http->requests[1]->url, PHP_URL_QUERY), $query);
        self::assertSame(['url' => $own], $query, 'чужая подписка цела');
        self::assertStringStartsWith(
            Max::DEFAULT_BASE_URL . '/subscriptions?',
            $http->requests[1]->url
        );
        // Адрес в списке дважды – всё равно один DELETE.
        $http = new FakeClient([
            FakeClient::json(['subscriptions' => [['url' => $own], ['url' => $own]]]),
            FakeClient::json(['success' => true]),
        ]);
        (new Max(new Config('token'), $http))->deleteWebhook($own);
        self::assertCount(2, $http->requests);
        // Своей нет (уже снята) – ничего не делаем, как Telegram.
        $http = new FakeClient([
            FakeClient::json(['subscriptions' => [['url' => 'https://other.example/hook']]]),
        ]);
        (new Max(new Config('token'), $http))->deleteWebhook($own);
        self::assertCount(1, $http->requests);
        foreach (
            ['http://example.com/hook', 'https://u:p@example.com/', 'https://example.com/#x', '']
            as $url
        ) {
            $http = new FakeClient();
            try {
                (new Max(new Config('token'), $http))->deleteWebhook($url);
                self::fail('Invalid URL accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $http->requests);
            }
        }
    }
    public function testTelegramDeleteWebhookRemovesOnlyOwnUrl(): void
    {
        $info = fn(string $url) => FakeClient::json(['ok' => true, 'result' => ['url' => $url]]);
        // Свой адрес – снимается.
        $http = new FakeClient([
            $info('https://example.com/x'),
            FakeClient::json(['ok' => true, 'result' => true]),
        ]);
        (new Telegram(new Config('123:token'), $http))->deleteWebhook('https://example.com/x');
        self::assertCount(2, $http->requests);
        self::assertStringEndsWith('/getWebhookInfo', $http->requests[0]->url);
        self::assertStringEndsWith('/deleteWebhook', $http->requests[1]->url);
        // Чужой или пустой – вебхук другого сервиса не трогаем.
        foreach (['https://other.example/hook', ''] as $current) {
            $http = new FakeClient([$info($current)]);
            (new Telegram(new Config('123:token'), $http))->deleteWebhook('https://example.com/x');
            self::assertCount(1, $http->requests, $current);
        }
        // Без адреса – снимается любой, без проверки.
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => true])]);
        (new Telegram(new Config('123:token'), $http))->deleteWebhook();
        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/deleteWebhook', $http->requests[0]->url);
        // Битый ответ getWebhookInfo – ошибка, а не молчаливое «не наш».
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => []])]);
        try {
            (new Telegram(new Config('123:token'), $http))->deleteWebhook('https://example.com/x');
            self::fail('invalid_webhook_info expected');
        } catch (\diBot\Exception\ApiException $e) {
            self::assertSame('invalid_webhook_info', $e->reason);
        }
        $this->expectException(\InvalidArgumentException::class);
        $api = new Telegram(new Config('123:token'), new FakeClient());
        $api->deleteWebhook('http://example.com/x');
    }
    public function testPolledUpdatesKeepRaw(): void
    {
        $item = [
            'update_id' => 7,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 5, 'type' => 'private'],
                'from' => ['id' => 5],
                'text' => 'hi',
            ],
        ];
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => [$item]])]);
        $batch = (new Telegram(new Config('123:token'), $http))->getUpdates('1');
        self::assertSame($item, $batch->updates[0]->raw);
    }
    public function testWebhookMaxConnectionsIsConfigured(): void
    {
        $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => true])]);
        $api = new Telegram(new Config('123:token', webhookSecret: 'secret', webhookMaxConnections: 1), $http);
        $api->setWebhook('https://example.com/hook');
        self::assertSame(1, json_decode($http->requests[0]->body, true)['max_connections']);
        foreach ([0, 101] as $bad) {
            try {
                new Config('123:token', webhookMaxConnections: $bad);
                self::fail("$bad accepted");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testUpdateTypesAreSharedByWebhookAndPolling(): void
    {
        self::assertSame(['message', 'callback_query', 'my_chat_member'], Telegram::UPDATE_TYPES);
        self::assertContains('bot_stopped', Max::UPDATE_TYPES);
        self::assertContains('bot_started', Max::UPDATE_TYPES);
        $config = new Config('123:token', webhookSecret: 'secret');
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => true]),
            FakeClient::json(['ok' => true, 'result' => []]),
        ]);
        $api = new Telegram($config, $http);
        $api->setWebhook('https://example.com/hook');
        self::assertArrayNotHasKey(
            'max_connections',
            json_decode($http->requests[0]->body, true),
            'без настройки – умолчание Telegram'
        );
        $api->getUpdates('1');
        foreach ($http->requests as $request) {
            self::assertSame(
                Telegram::UPDATE_TYPES,
                json_decode($request->body, true)['allowed_updates']
            );
        }
        $http = new FakeClient([
            FakeClient::json(['success' => true]),
            FakeClient::json(['updates' => [], 'marker' => 5]),
        ]);
        $api = new Max($config, $http);
        $api->setWebhook('https://example.com/hook');
        $api->getUpdates('1');
        self::assertSame(
            Max::UPDATE_TYPES,
            json_decode($http->requests[0]->body, true)['update_types']
        );
        $query = [];
        parse_str((string) parse_url($http->requests[1]->url, PHP_URL_QUERY), $query);
        self::assertSame(Max::UPDATE_TYPES, explode(',', $query['types']));
    }
    public function testPollingUsesNativeOffsetsAndIgnoresUnsupportedUpdates(): void
    {
        $http = new FakeClient([
            FakeClient::json([
                'ok' => true,
                'result' => [UpdateTest::telegram(), ['update_id' => 15, 'unsupported' => []]],
            ]),
        ]);
        $batch = (new Telegram(new Config('123:secret'), $http))->getUpdates('3');
        self::assertSame('16', $batch->cursor);
        self::assertCount(1, $batch->updates);
        self::assertGreaterThan(25, $http->requests[0]->timeout);
        $http = new FakeClient([
            FakeClient::json(['updates' => [UpdateTest::max()], 'marker' => '9007199254740993']),
        ]);
        $batch = (new Max(new Config('token'), $http))->getUpdates('50');
        self::assertSame('9007199254740993', $batch->cursor);
        self::assertStringContainsString('marker=50', $http->requests[0]->url);
    }
    public function testSourceSizeIsCheckedAgainBeforeUpload(): void
    {
        $http = new FakeClient();
        $media = $this->media();
        file_put_contents($this->file, 'changed');
        try {
            (new Telegram(new Config('123:secret'), $http))->sendMedia(
                '1',
                $media,
                Transport::Upload
            );
            self::fail();
        } catch (\RuntimeException) {
            self::assertCount(0, $http->requests);
        }
    }
    public function testMaxCustomApiHostDoesNotReceiveRussianTrustBundle(): void
    {
        $http = new FakeClient([FakeClient::json(['user_id' => 1])]);
        (new Max(new Config('token', 'https://custom.example'), $http))->getMe();
        self::assertNull($http->requests[0]->caBundle);
    }

    public function testMaxEditCaptionPreservesMediaAndReplacesKeyboard(): void
    {
        $http = new FakeClient([
            FakeClient::json([
                'body' => [
                    'attachments' => [
                        ['type' => 'image', 'payload' => ['token' => 'photo']],
                        ['type' => 'inline_keyboard', 'payload' => ['buttons' => []]],
                    ],
                ],
            ]),
            FakeClient::json(['success' => true]),
        ]);
        (new Max(new Config('token'), $http))->editMessage('1', 'mid', 'Answered', isCaption: true);
        $body = json_decode($http->requests[1]->body, true);
        self::assertSame('photo', $body['attachments'][0]['payload']['token']);
        self::assertCount(1, $body['attachments']);
    }

    public function testNonEmptyMaxBatchCannotSilentlyLoseItsCursor(): void
    {
        $http = new FakeClient([
            FakeClient::json(['updates' => [UpdateTest::max()], 'marker' => null]),
        ]);
        $this->expectException(ApiException::class);
        (new Max(new Config('token'), $http))->getUpdates('1');
    }
    public function testSharedCommandListUsesNativeFieldNames(): void
    {
        $commands = [['command' => 'help', 'description' => 'Помощь']];
        $tg = new FakeClient([FakeClient::json(['ok' => true, 'result' => true])]);
        (new Telegram(new Config('token'), $tg))->setCommands($commands);
        self::assertSame($commands, json_decode($tg->requests[0]->body, true)['commands']);
        $max = new FakeClient([FakeClient::json(['commands' => []])]);
        (new Max(new Config('token'), $max))->setCommands($commands);
        self::assertSame('PATCH', $max->requests[0]->method);
        self::assertStringEndsWith('/me/commands', $max->requests[0]->url);
        self::assertSame(
            [['name' => 'help', 'description' => 'Помощь']],
            json_decode($max->requests[0]->body, true)['commands']
        );
        $this->expectException(\InvalidArgumentException::class);
        (new Max(new Config('token'), new FakeClient()))->setCommands([
            ['command' => 'Bad-name', 'description' => 'Help'],
        ]);
    }
}
