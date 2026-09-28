<?php
namespace diBot\Tests;

use diBot\{AbstractController, AbstractUpdate, Config, CursorStore};
use diBot\Exception\{ApiException, DownloadException};
use diBot\Http\Response;
use diBot\Max\BotApi as Max;
use diBot\Telegram\BotApi as Telegram;
use PHPUnit\Framework\TestCase;

final class SecondReviewTest extends TestCase
{
    private function controller($api): AbstractController
    {
        return new class ($api) extends AbstractController {
            public array $pauses = [];
            public array $handled = [];
            protected function pollingPause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
            protected function handle(AbstractUpdate $update): void
            {
                $this->handled[] = $update;
            }
        };
    }
    private function store(): CursorStore
    {
        return new class implements CursorStore {
            public ?string $cursor = '1';
            public function load(): ?string
            {
                return $this->cursor;
            }
            public function save(?string $cursor): void
            {
                $this->cursor = $cursor;
            }
        };
    }
    public function testWebhookSecretsUsePlatformSpecificBoundsAndNativeFields(): void
    {
        foreach ([Max::class => 5, Telegram::class => 1] as $class => $min) {
            foreach (['', str_repeat('a', $min - 1), 'abcd!', str_repeat('a', 257)] as $secret) {
                $http = new FakeClient();
                try {
                    (new $class(new Config('token', webhookSecret: $secret), $http))->setWebhook(
                        'https://example.com/webhook'
                    );
                    self::fail('Invalid secret accepted');
                } catch (\InvalidArgumentException) {
                    self::assertSame([], $http->requests);
                }
            }
            foreach ([str_repeat('a', $min), str_repeat('_', 256), 'abc-_0'] as $secret) {
                $http = new FakeClient([FakeClient::json(['ok' => true, 'success' => true])]);
                (new $class(new Config('token', webhookSecret: $secret), $http))->setWebhook(
                    'https://example.com/webhook'
                );
                $body = json_decode($http->requests[0]->body, true);
                self::assertSame('https://example.com/webhook', $body['url']);
                self::assertSame($secret, $body[$class === Max::class ? 'secret' : 'secret_token']);
                self::assertCount(1, $http->requests);
            }
        }
        $controller = $this->controller(
            new Max(new Config('token', webhookSecret: 'secret'), new FakeClient())
        );
        $raw = json_encode(UpdateTest::max());
        foreach (['', 'wrong'] as $secret) {
            self::assertSame(['ok' => true], $controller->webhook($raw, $secret));
            self::assertSame([], $controller->handled);
        }
        self::assertSame(['ok' => true], $controller->webhook($raw, 'secret'));
        self::assertCount(1, $controller->handled);
    }
    public function testPollingRetriesServerAndMalformedResponsesOnBothPlatforms(): void
    {
        foreach ([Telegram::class, Max::class] as $class) {
            $setup = $class === Max::class ? ['subscriptions' => []] : ['ok' => true];
            $success =
                $class === Max::class
                    ? ['updates' => [UpdateTest::max()], 'marker' => '13']
                    : ['ok' => true, 'result' => [UpdateTest::telegram()]];
            foreach (
                [new Response(502, '<html>'), new Response(504, '{}'), new Response(200, 'invalid')]
                as $failure
            ) {
                $http = new FakeClient([
                    FakeClient::json($setup),
                    $failure,
                    FakeClient::json($success),
                ]);
                $controller = $this->controller(new $class(new Config('token'), $http));
                $store = $this->store();
                $controller->poll($store, 1);
                self::assertSame([1], $controller->pauses);
                self::assertSame('13', $store->load());
                self::assertSame($http->requests[1]->url, $http->requests[2]->url);
                self::assertSame($http->requests[1]->body, $http->requests[2]->body);
            }
        }
    }
    public function testServerRetryBudgetStopsWithoutCheckpoint(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true]),
            ...array_fill(0, 4, new Response(502, '{}')),
        ]);
        $controller = $this->controller(new Telegram(new Config('token'), $http));
        $store = $this->store();
        try {
            $controller->poll($store, 1);
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(502, $e->httpStatus);
        }
        self::assertSame([1, 2, 4], $controller->pauses);
        self::assertSame('1', $store->load());
    }
    public function testSafeClientReasonsSurviveAndDoNotRetryConfigurationErrors(): void
    {
        foreach (
            ['tls_untrusted_ca', 'tls_ca_file', 'tls_client_certificate', 'response_too_large']
            as $reason
        ) {
            $http = new FakeClient([
                FakeClient::json(['ok' => true]),
                new ApiException(0, $reason),
            ]);
            $controller = $this->controller(new Telegram(new Config('token'), $http));
            try {
                $controller->poll($this->store(), 1);
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($reason, $e->reason);
                self::assertNull($e->getPrevious());
            }
            self::assertSame([], $controller->pauses);
            self::assertCount(2, $http->requests);
        }
        $http = new FakeClient([new ApiException(0, 'https://SECRET')]);
        try {
            (new Max(new Config('token'), $http))->getMe();
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('network_error', $e->reason);
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }
    public function testCaptionEditNormalizesReadOnlyFieldsAndRefusesUnknownMedia(): void
    {
        $media = [
            'type' => 'image',
            'payload' => ['photo_id' => 123, 'token' => 'file', 'url' => 'https://cdn.example/img'],
        ];
        $http = new FakeClient([
            FakeClient::json(['body' => ['attachments' => [$media]]]),
            FakeClient::json(['success' => true]),
        ]);
        (new Max(new Config('token'), $http))->editMessage('1', 'mid', 'caption', isCaption: true);
        self::assertSame(
            [['type' => 'image', 'payload' => ['token' => 'file']]],
            json_decode($http->requests[1]->body, true)['attachments']
        );
        foreach (
            [
                'bad',
                null,
                ['bad'],
                [['type' => 'image', 'payload' => 'bad']],
                [['type' => 'file', 'payload' => []]],
                [['type' => 'video', 'payload' => ['token' => 'v']]],
            ]
            as $attachments
        ) {
            $http = new FakeClient([FakeClient::json(['body' => ['attachments' => $attachments]])]);
            try {
                (new Max(new Config('token'), $http))->editMessage(
                    '1',
                    'mid',
                    'caption',
                    isCaption: true
                );
                self::fail();
            } catch (ApiException $e) {
                self::assertContains($e->reason, ['invalid_attachments', 'unsupported_attachment']);
            }
            self::assertCount(1, $http->requests, 'Must not PUT and accidentally remove media');
        }
    }
    public function testTelegramDownloadConfigurationErrorsUseDownloadContract(): void
    {
        $http = new FakeClient();
        try {
            (new Telegram(
                new Config('token', baseUrl: 'https://proxy.example'),
                $http
            ))->downloadAttachment('ref');
            self::fail();
        } catch (DownloadException $e) {
            self::assertSame('invalid_configuration', $e->reason);
            self::assertTrue($e->permanent);
        }
        self::assertSame([], $http->requests);
        foreach (['tls_ca_file', 'tls_untrusted_ca'] as $reason) {
            try {
                (new Telegram(
                    new Config('token'),
                    new FakeClient([new ApiException(0, $reason)])
                ))->downloadAttachment('ref');
                self::fail();
            } catch (DownloadException $e) {
                self::assertSame($reason, $e->reason);
                self::assertTrue($e->permanent);
            }
        }
    }
    public function testMalformedUpdatesDoNotPoisonUsableBatchAndUnknownOffsetIsNeverGuessed(): void
    {
        $bad = ['bad', [], ['update_id' => []], ['update_id' => true], ['update_id' => 1.2]];
        $http = new FakeClient([
            FakeClient::json([
                'ok' => true,
                'result' => [...$bad, UpdateTest::telegram(), ...$bad],
            ]),
        ]);
        $batch = (new Telegram(new Config('token'), $http))->getUpdates('1');
        self::assertSame('13', $batch->cursor);
        self::assertCount(1, $batch->updates);
        $http = new FakeClient([
            FakeClient::json(['updates' => ['bad', UpdateTest::max()], 'marker' => '13']),
        ]);
        $batch = (new Max(new Config('token'), $http))->getUpdates('1');
        self::assertSame('13', $batch->cursor);
        self::assertCount(1, $batch->updates);
        $this->expectException(ApiException::class);
        (new Telegram(
            new Config('token'),
            new FakeClient([FakeClient::json(['ok' => true, 'result' => $bad])])
        ))->getUpdates('1');
    }
    public function testNonRedirectStatusesAndMissingLocationAreNotPermanentUrlFailures(): void
    {
        foreach ([300, 301, 302, 303, 304, 307, 308] as $status) {
            try {
                (new Max(
                    new Config('token'),
                    new FakeClient([new Response($status, '')])
                ))->downloadAttachment('', 'https://cdn.example/file');
                self::fail();
            } catch (DownloadException $e) {
                self::assertSame('invalid_redirect', $e->reason);
                self::assertSame($status, $e->httpStatus);
                self::assertFalse($e->permanent);
            }
        }
    }
    public function testMaxMissingLocaleStaysUnknownAndMalformedStartsHaveNoDedupId(): void
    {
        self::assertSame(
            '',
            \diBot\Max\Update::fromArray(UpdateTest::max())->userProfile['language']
        );
        $raw = ['update_type' => 'bot_started', 'chat_id' => 1, 'user' => ['user_id' => 2]];
        foreach ([null, '', [], -1, false, 'bad'] as $timestamp) {
            self::assertNull(\diBot\Max\Update::fromArray($raw + ['timestamp' => $timestamp]));
        }
        $one = \diBot\Max\Update::fromArray($raw + ['timestamp' => 1]);
        $two = \diBot\Max\Update::fromArray($raw + ['timestamp' => 2]);
        self::assertNotSame($one->updateId, $two->updateId);
        self::assertSame(
            $one->updateId,
            \diBot\Max\Update::fromArray($raw + ['timestamp' => 1])->updateId
        );
    }
    public function testMultipartFailureLogsShapeWithoutFileOrCaptionContents(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };
        $path = tempnam(sys_get_temp_dir(), 'di-shape-');
        file_put_contents($path, 'PRIVATE_BYTES');
        try {
            $http = new FakeClient([FakeClient::json(['ok' => false], 400)]);
            try {
                (new Telegram(new Config('token'), $http, $logger))->sendMedia(
                    '1',
                    new \diBot\Outgoing\Media(
                        'document',
                        'application/pdf',
                        13,
                        localPath: $path,
                        caption: 'PRIVATE_CAPTION'
                    ),
                    \diBot\Outgoing\Transport::Upload
                );
                self::fail();
            } catch (ApiException) {
                $shape = $logger->records[0]['shape'];
                self::assertStringContainsString('document:string(13 bytes)', $shape);
                self::assertStringContainsString('caption:string(15 bytes)', $shape);
                self::assertStringNotContainsString('PRIVATE', $shape);
            }
        } finally {
            unlink($path);
        }
    }
}
