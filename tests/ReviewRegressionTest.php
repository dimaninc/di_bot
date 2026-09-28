<?php
namespace diBot\Tests;

use diBot\{AbstractBotApi, AbstractController, AbstractUpdate, Config, CursorStore, Keyboard};
use diBot\Exception\{ApiException, MediaNotReadyException};
use diBot\Http\Response;
use diBot\Outgoing\{Media, Sender};
use diBot\Telegram\BotApi as Telegram;
use diBot\Max\BotApi as Max;
use PHPUnit\Framework\TestCase;

final class ReviewController extends AbstractController
{
    public array $handled = [];
    public array $pauses = [];
    protected function handle(AbstractUpdate $update): void
    {
        $this->handled[] = $update->updateId;
        if ($update->updateId === '12') {
            throw new \RuntimeException('PRIVATE CONTENT');
        }
    }
    protected function pollingPause(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }
}

final class ReviewRegressionTest extends TestCase
{
    private function ok(array $updates = []): Response
    {
        return FakeClient::json(['ok' => true, 'result' => $updates]);
    }
    private function controller(FakeClient $http): ReviewController
    {
        return new ReviewController(new Telegram(new Config('123:token'), $http));
    }
    private function store(): CursorStore
    {
        return new class implements CursorStore {
            public ?string $cursor = '1';
            public array $saves = [];
            public function load(): ?string
            {
                return $this->cursor;
            }
            public function save(?string $cursor): void
            {
                $this->cursor = $cursor;
                $this->saves[] = $cursor;
            }
        };
    }
    public function testPoisonUpdateDoesNotBlockLaterUpdatesOrReplayOnRestart(): void
    {
        $updates = [];
        foreach ([11, 12, 13] as $id) {
            $raw = UpdateTest::telegram();
            $raw['update_id'] = $id;
            $updates[] = $raw;
        }
        $http = new FakeClient([$this->ok(), $this->ok($updates), $this->ok(), $this->ok()]);
        $controller = $this->controller($http);
        $store = $this->store();
        $controller->poll($store, 1);
        self::assertSame(['11', '12', '13'], $controller->handled);
        self::assertSame('14', $store->load());
        $controller->poll($store, 1);
        self::assertSame(['11', '12', '13'], $controller->handled);
        self::assertSame('14', json_decode($http->requests[3]->body, true)['offset']);
    }
    public function testPollingRecoversNetworkErrorAtSameCursorWithoutConsumingBatchLimit(): void
    {
        $http = new FakeClient([
            $this->ok(),
            new \RuntimeException('PRIVATE'),
            $this->ok([UpdateTest::telegram()]),
        ]);
        $controller = $this->controller($http);
        $store = $this->store();
        $controller->poll($store, 1);
        self::assertSame([1], $controller->pauses);
        self::assertSame('13', $store->load());
        self::assertSame(['1', '13'], $store->saves);
        self::assertSame($http->requests[1]->body, $http->requests[2]->body);
    }
    public function testPollingNetworkRetryBudgetIsFiniteAndKeepsCursor(): void
    {
        $http = new FakeClient([
            $this->ok(),
            ...array_fill(0, 4, new \RuntimeException('PRIVATE')),
        ]);
        $controller = $this->controller($http);
        $store = $this->store();
        try {
            $controller->poll($store, 1);
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('network_error', $e->reason);
        }
        self::assertSame([1, 2, 4], $controller->pauses);
        self::assertSame(['1'], $store->saves);
        self::assertCount(5, $http->requests);
    }
    public function testPollingCancellationStopsRetryWithoutAcknowledgingBatch(): void
    {
        $http = new FakeClient([$this->ok(), new \RuntimeException()]);
        $controller = $this->controller($http);
        $store = $this->store();
        $controller->poll($store, 1, fn() => count($http->requests) < 2);
        self::assertSame([], $controller->pauses);
        self::assertSame(['1'], $store->saves);
    }
    public function testPollingDoesNotRetryApiRejection(): void
    {
        $http = new FakeClient([
            $this->ok(),
            FakeClient::json(['ok' => false, 'error_code' => 401], 401),
        ]);
        $controller = $this->controller($http);
        try {
            $controller->poll($this->store(), 1);
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(401, $e->httpStatus);
        }
        self::assertSame([], $controller->pauses);
        self::assertCount(2, $http->requests);
    }
    public function testCheckpointFailureStopsBeforeReadingMoreUpdates(): void
    {
        $http = new FakeClient([$this->ok(), $this->ok([UpdateTest::telegram()])]);
        $store = new class implements CursorStore {
            public int $saves = 0;
            public function load(): ?string
            {
                return '1';
            }
            public function save(?string $cursor): void
            {
                if (++$this->saves > 1) {
                    throw new \RuntimeException('store error');
                }
            }
        };
        try {
            $this->controller($http)->poll($store, 2);
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertSame('store error', $e->getMessage());
        }
        self::assertCount(2, $http->requests);
    }
    public function testEmptyMaxBatchMayOmitMarkerWithoutResettingCursor(): void
    {
        foreach ([null, '9007199254740993'] as $cursor) {
            foreach ([['updates' => []], ['updates' => [], 'marker' => null]] as $body) {
                $api = new Max(new Config('token'), new FakeClient([FakeClient::json($body)]));
                self::assertSame($cursor, $api->getUpdates($cursor)->cursor);
            }
        }
        $this->expectException(ApiException::class);
        (new Max(
            new Config('token'),
            new FakeClient([FakeClient::json(['updates' => [UpdateTest::max()]])])
        ))->getUpdates('10');
    }
    public function testMaxRemovesKeyboardWithoutEmptyAttachment(): void
    {
        foreach ([null, new Keyboard()] as $keyboard) {
            $http = new FakeClient([
                FakeClient::json(['body' => ['attachments' => []]]),
                FakeClient::json(['success' => true]),
            ]);
            (new Max(new Config('token'), $http))->editMessage('1', 'mid', 'edited', $keyboard);
            self::assertSame([], json_decode($http->requests[1]->body, true)['attachments']);
        }
        $http = new FakeClient([$this->ok()]);
        (new Telegram(new Config('token'), $http))->editMessage('1', '2', 'edited');
        self::assertSame(
            ['inline_keyboard' => []],
            json_decode($http->requests[0]->body, true)['reply_markup']
        );
    }
    public function testPendingUploadTokenSurvivesSenderAndCanBeReusedWithoutUploading(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'di-pending-');
        file_put_contents($path, 'pdf');
        try {
            $http = new FakeClient([
                FakeClient::json([
                    'url' => 'https://fu.oneme.ru/upload',
                    'token' => 'PRIVATE_FILE_TOKEN',
                ]),
                new Response(204, ''),
                FakeClient::json(['code' => 'attachment.not.ready'], 400),
                FakeClient::json(['message' => []]),
            ]);
            $api = new Max(new Config('token'), $http);
            try {
                (new Sender($api))->send(
                    '1',
                    'caption',
                    fn() => new Media('document', 'application/pdf', 3, localPath: $path)
                );
                self::fail();
            } catch (MediaNotReadyException $e) {
                self::assertSame('document', $e->kind);
                self::assertSame('PRIVATE_FILE_TOKEN', $e->getFileId());
                self::assertCount(3, $http->requests);
                ob_start();
                var_dump($e);
                $debug = ob_get_clean();
                self::assertStringNotContainsString('PRIVATE_FILE_TOKEN', $debug);
                self::assertStringNotContainsString('PRIVATE_FILE_TOKEN', $e->getMessage());
                $api->sendFileId('1', $e->kind, $e->getFileId(), 'caption');
                self::assertCount(4, $http->requests);
                self::assertStringContainsString('/messages?', $http->requests[3]->url);
                self::assertSame(
                    'PRIVATE_FILE_TOKEN',
                    json_decode($http->requests[3]->body, true)['attachments'][0]['payload'][
                        'token'
                    ]
                );
            }
        } finally {
            unlink($path);
        }
    }
    public function testSenderDoesNotSendFallbackAfterAmbiguousMaxDelivery(): void
    {
        foreach (
            [
                new \RuntimeException('timeout'),
                new Response(200, 'broken'),
                new Response(502, 'bad gateway'),
            ]
            as $response
        ) {
            $http = new FakeClient([$response]);
            try {
                (new Sender(new Max(new Config('token'), $http)))->send(
                    '1',
                    'caption',
                    fn() => new Media('photo', 'image/png', 1, 'https://example.com/pic')
                );
                self::fail();
            } catch (ApiException $e) {
                self::assertTrue($e->isDeliveryUncertain());
            }
            self::assertCount(1, $http->requests);
        }
    }
    public function testLostMaxReplyAfterUploadDoesNotSendFallbackOrReupload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'di-uncertain-');
        file_put_contents($path, 'pdf');
        try {
            $http = new FakeClient([
                FakeClient::json(['url' => 'https://fu.oneme.ru/upload', 'token' => 'file']),
                new Response(204, ''),
                new \RuntimeException('timeout'),
            ]);
            try {
                (new Sender(new Max(new Config('token'), $http)))->send(
                    '1',
                    'caption',
                    fn() => new Media('document', 'application/pdf', 3, localPath: $path)
                );
                self::fail();
            } catch (ApiException $e) {
                self::assertSame('network_error', $e->reason);
            }
            self::assertCount(3, $http->requests);
        } finally {
            unlink($path);
        }
    }
    public function testLostTelegramReplyDoesNotSendFallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'di-uncertain-');
        file_put_contents($path, 'pdf');
        try {
            $http = new FakeClient([new \RuntimeException('timeout')]);
            try {
                (new Sender(new Telegram(new Config('token'), $http)))->send(
                    '1',
                    'caption',
                    fn() => new Media('document', 'application/pdf', 3, localPath: $path)
                );
                self::fail();
            } catch (ApiException $e) {
                self::assertSame('network_error', $e->reason);
            }
            self::assertCount(1, $http->requests);
        } finally {
            unlink($path);
        }
    }
    public function testPendingTokenIsPreservedAfterCliRetryBudget(): void
    {
        $http = new FakeClient(
            array_fill(0, 4, FakeClient::json(['code' => 'attachment.not.ready'], 400))
        );
        $api = new class (new Config('token', retryMediaInCli: true), $http) extends Max {
            public array $pauses = [];
            protected function pause(int $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
        try {
            $api->sendFileId('1', 'document', 'file-token');
            self::fail();
        } catch (MediaNotReadyException $e) {
            self::assertSame('file-token', $e->getFileId());
        }
        self::assertSame([2, 4, 8], $api->pauses);
        self::assertCount(4, $http->requests);
    }

    public function testStorageRateLimitDoesNotTriggerTextFallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'di-rate-');
        file_put_contents($path, 'pdf');
        try {
            $http = new FakeClient([
                FakeClient::json(['url' => 'https://fu.oneme.ru/upload', 'token' => 'file']),
                new Response(429, '', ['retry-after' => '5']),
            ]);
            try {
                (new Sender(new Max(new Config('token'), $http)))->send(
                    '1',
                    'caption',
                    fn() => new Media('document', 'application/pdf', 3, localPath: $path)
                );
                self::fail();
            } catch (ApiException $e) {
                self::assertSame(5, $e->rateLimitDelay());
            }
            self::assertCount(2, $http->requests);
        } finally {
            unlink($path);
        }
    }
}
