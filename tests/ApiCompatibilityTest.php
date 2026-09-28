<?php
namespace diBot\Tests;
use diBot\Config;
use diBot\Exception\ApiException;
use diBot\Http\Response;
use diBot\Outgoing\{Media, Sender};
use diBot\Telegram\BotApi as Telegram;
use diBot\Max\BotApi as Max;
use PHPUnit\Framework\TestCase;

final class ApiCompatibilityTest extends TestCase
{
    public function testMaxSilentCallbackNeverUsesNetworkButNotificationDoes(): void
    {
        $http = new FakeClient([FakeClient::json(['success' => true])]);
        $api = new Max(new Config('token'), $http);
        self::assertSame(['ok' => true], $api->answerCallback('id'));
        self::assertSame([], $http->requests);
        $api->answerCallback('id', 'Готово');
        self::assertCount(1, $http->requests);
        self::assertSame(['notification' => 'Готово'], json_decode($http->requests[0]->body, true));
        self::assertStringContainsString('/answers?callback_id=id', $http->requests[0]->url);
    }
    public function testBlockedDetectionUsesCodesNotMaxErrorText(): void
    {
        foreach (
            [
                'chat.denied' => true,
                'chat.blocked' => true,
                'bot.blocked' => true,
                'user.blocked' => true,
                'too.many.requests' => false,
                'attachment.blocked' => false,
                'auth.failed' => false,
            ]
            as $code => $blocked
        ) {
            try {
                (new Max(
                    new Config('token'),
                    new FakeClient([
                        FakeClient::json(
                            ['code' => $code, 'message' => 'dialog suspended blocked'],
                            403
                        ),
                    ])
                ))->sendMessage('1', 'text');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($blocked, $e->blocked, $code);
            }
        }
        foreach ([403 => true, 400 => false] as $status => $blocked) {
            try {
                (new Telegram(
                    new Config('token'),
                    new FakeClient([
                        FakeClient::json(
                            ['ok' => false, 'error_code' => $status, 'description' => 'Forbidden'],
                            $status
                        ),
                    ])
                ))->sendMessage('1', 'text');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($blocked, $e->blocked);
            }
        }
    }
    public function testBlockedFallbackIsOptionalAndOtherRefusalsStillFallBack(): void
    {
        foreach (
            [['chat.denied', false, 1], ['chat.denied', true, 2], ['attachment.invalid', false, 2]]
            as [$code, $fallback, $count]
        ) {
            $http = new FakeClient([
                FakeClient::json(['code' => $code], 403),
                FakeClient::json(['message' => []]),
            ]);
            try {
                (new Sender(new Max(new Config('token'), $http)))->send(
                    '1',
                    'text',
                    fn() => new Media('photo', 'image/png', 1, 'https://cdn.example/file'),
                    fallbackWhenBlocked: $fallback
                );
            } catch (ApiException $e) {
                self::assertTrue($e->blocked);
                self::assertFalse($fallback);
            }
            self::assertCount($count, $http->requests);
        }
    }
    public function testRateLimitsPreserveDelayAndPreventImmediateFallback(): void
    {
        foreach (
            [
                [
                    'telegram',
                    FakeClient::json(
                        ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 7]],
                        429
                    ),
                    7,
                ],
                ['telegram', new Response(429, 'not-json', ['retry-after' => '13']), 13],
                ['telegram', FakeClient::json(['ok' => false, 'error_code' => 429]), 1],
                ['max', FakeClient::json(['code' => 'too.many.requests']), 1],
                ['max', new Response(429, '', ['retry-after' => '4']), 4],
            ]
            as [$platform, $response, $delay]
        ) {
            $http = new FakeClient([$response]);
            $api =
                $platform === 'telegram'
                    ? new Telegram(new Config('token'), $http)
                    : new Max(new Config('token'), $http);
            try {
                $api->sendMessage('1', 'text');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame($delay, $e->rateLimitDelay());
                self::assertSame('rate_limit', $e->reason);
                self::assertFalse($e->blocked);
            }
            self::assertCount(1, $http->requests);
        }
        $http = new FakeClient([FakeClient::json(['code' => 'too.many.requests'], 429)]);
        try {
            (new Sender(new Max(new Config('token'), $http)))->send(
                '1',
                'text',
                fn() => new Media('photo', 'image/png', 1, 'https://cdn.example/file')
            );
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(1, $e->rateLimitDelay());
        }
        self::assertCount(1, $http->requests);
    }
    public function testPollerHonoursRateLimitDelayAtSameCursor(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => true]),
            FakeClient::json(
                ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 9]],
                429
            ),
            FakeClient::json(['ok' => true, 'result' => [UpdateTest::telegram()]]),
        ]);
        $controller = new ReviewController(new Telegram(new Config('token'), $http));
        $store = new MemoryCursor('1');
        $controller->poll($store, 1);
        self::assertSame([9], $controller->pauses);
        self::assertSame('13', $store->load());
        self::assertSame($http->requests[1]->body, $http->requests[2]->body);
    }
}
