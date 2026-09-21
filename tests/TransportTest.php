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
        self::assertSame([], $body['attachments'][1]['payload']['buttons']);
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
