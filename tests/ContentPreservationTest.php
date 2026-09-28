<?php
namespace diBot\Tests;

use diBot\{Button, Config, Keyboard};
use diBot\Exception\ApiException;
use diBot\Http\Response;
use diBot\Outgoing\{Media, Sender};
use diBot\Max\BotApi as Max;
use diBot\Telegram\BotApi as Telegram;
use PHPUnit\Framework\TestCase;

final class ContentPreservationTest extends TestCase
{
    public function testLongCaptionPreservesFullTextAndKeyboardInsteadOfShortFallback(): void
    {
        foreach ([str_repeat('x', 1360), str_repeat('😀', 513)] as $text) {
            $http = new FakeClient([
                FakeClient::json(['ok' => true, 'result' => ['message_id' => 1]]),
            ]);
            $keyboard = new Keyboard([[Button::callback('Next', 'next')]]);
            $result = (new Sender(new Telegram(new Config('token'), $http)))->send(
                '1',
                $text,
                fn() => new Media('document', 'application/pdf', 3, localPath: '/unused'),
                $keyboard,
                'Download: https://example.com/result.pdf'
            );
            self::assertSame(1, $result['result']['message_id']);
            self::assertCount(1, $http->requests);
            self::assertStringEndsWith('/sendMessage', $http->requests[0]->url);
            $body = json_decode($http->requests[0]->body, true);
            self::assertSame($text, $body['text']);
            self::assertSame(
                'next',
                $body['reply_markup']['inline_keyboard'][0][0]['callback_data']
            );
        }
    }
    public function testCaptionAtBoundaryStillUploadsMedia(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'di-caption-');
        file_put_contents($path, 'pdf');
        try {
            $text = str_repeat('😀', 512);
            $http = new FakeClient([FakeClient::json(['ok' => true])]);
            (new Sender(new Telegram(new Config('token'), $http)))->send(
                '1',
                $text,
                fn() => new Media('document', 'application/pdf', 3, localPath: $path),
                fallbackText: 'fallback'
            );
            self::assertCount(1, $http->requests);
            self::assertStringEndsWith('/sendDocument', $http->requests[0]->url);
            self::assertStringContainsString($text, $http->requests[0]->body);
        } finally {
            unlink($path);
        }
    }
    public function testLongTextFailureIsNotRetriedAsFallback(): void
    {
        foreach ([FakeClient::json(['ok' => false], 400), new Response(502, 'error')] as $failure) {
            $http = new FakeClient([$failure]);
            try {
                (new Sender(new Telegram(new Config('token'), $http)))->send(
                    '1',
                    str_repeat('x', 1360),
                    fn() => new Media('document', 'application/pdf', 3, localPath: '/unused'),
                    fallbackText: 'fallback'
                );
                self::fail();
            } catch (ApiException) {
                self::assertCount(1, $http->requests);
            }
        }
    }
    public function testTextAboveMessageLimitIsRejectedWithoutSendingShortFallback(): void
    {
        $http = new FakeClient();
        try {
            (new Sender(new Telegram(new Config('token'), $http)))->send(
                '1',
                str_repeat('x', 4097),
                fn() => new Media('document', 'application/pdf', 3, localPath: '/unused'),
                fallbackText: 'fallback'
            );
            self::fail();
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests);
        }
    }
    public function testMaxDefaultEditCannotDeleteMediaOrUnknownAttachments(): void
    {
        foreach (['image', 'file', 'video', 'future_type'] as $type) {
            $http = new FakeClient([
                FakeClient::json([
                    'body' => [
                        'attachments' => [
                            ['type' => 'inline_keyboard', 'payload' => ['buttons' => []]],
                            ['type' => $type, 'payload' => ['token' => 'file']],
                        ],
                    ],
                ]),
            ]);
            try {
                (new Max(new Config('token'), $http))->editMessage('1', 'mid', 'new text');
                self::fail();
            } catch (ApiException $e) {
                self::assertSame('caption_required', $e->reason);
            }
            self::assertCount(1, $http->requests);
            self::assertSame('GET', $http->requests[0]->method);
        }
    }
    public function testMaxTextEditReadsThenRemovesOrReplacesOnlyKeyboard(): void
    {
        foreach ([null, new Keyboard([[Button::callback('Next', 'next')]])] as $keyboard) {
            foreach (
                [
                    [],
                    ['attachments' => []],
                    [
                        'attachments' => [
                            ['type' => 'inline_keyboard', 'payload' => ['buttons' => []]],
                        ],
                    ],
                ]
                as $body
            ) {
                $http = new FakeClient([
                    FakeClient::json(['body' => $body]),
                    FakeClient::json(['success' => true]),
                ]);
                (new Max(new Config('token'), $http))->editMessage(
                    '1',
                    'mid',
                    'new text',
                    $keyboard
                );
                self::assertSame('GET', $http->requests[0]->method);
                self::assertSame('PUT', $http->requests[1]->method);
                self::assertCount(2, $http->requests);
                $sent = json_decode($http->requests[1]->body, true);
                self::assertSame('new text', $sent['text']);
                self::assertSame(
                    $keyboard === null ? [] : [$keyboard->render(\diBot\Platform::Max)],
                    $sent['attachments']
                );
            }
        }
    }
    public function testMaxDefaultEditStopsOnUnreadableOrMalformedMessage(): void
    {
        foreach (
            [
                new Response(502, 'error'),
                FakeClient::json([]),
                FakeClient::json(['body' => 'bad']),
                FakeClient::json(['body' => ['attachments' => ['bad']]]),
            ]
            as $response
        ) {
            $http = new FakeClient([$response]);
            try {
                (new Max(new Config('token'), $http))->editMessage('1', 'mid', 'text');
                self::fail();
            } catch (ApiException) {
                self::assertCount(1, $http->requests);
                self::assertSame('GET', $http->requests[0]->method);
            }
        }
    }
}
