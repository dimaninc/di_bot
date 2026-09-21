<?php
namespace diBot\Tests;
use diBot\{Config, Keyboard};
use diBot\Outgoing\{Media, Policy, Sender, Transport};
use diBot\Telegram\BotApi as Telegram;
use diBot\Max\BotApi as Max;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testCeilingPrecedesUrlAndCaptionDecision(): void
    {
        $api = new Max(new Config('token'), new FakeClient());
        $media = new Media(
            'photo',
            'image/png',
            8 * 1024 * 1024,
            'https://example.com/file',
            caption: str_repeat('x', 5000)
        );
        self::assertSame('too_large', Policy::decide($media, $api)->reason);
        $media = new Media(
            'photo',
            'image/png',
            1,
            'https://example.com/file',
            caption: str_repeat('😀', 2001)
        );
        self::assertSame('caption_too_long', Policy::decide($media, $api)->reason);
    }
    public function testPolicyLocalUrlAndMissingSource(): void
    {
        $tg = new Telegram(new Config('token'), new FakeClient());
        $max = new Max(new Config('token'), new FakeClient());
        $photo = new Media('photo', 'image/png', 5 * 1024 * 1024, 'https://example.com/image');
        self::assertSame(Transport::Url, Policy::decide($photo, $max)->transport);
        self::assertSame(Transport::Refuse, Policy::decide($photo, $tg)->transport);
        $file = new Media('document', 'application/pdf', 1, 'https://example.com/file');
        self::assertSame(Transport::Refuse, Policy::decide($file, $max)->transport);
        $local = new Media(
            'document',
            'application/pdf',
            Telegram::FILE_MAX_BYTES,
            localPath: '/file'
        );
        self::assertSame(Transport::Upload, Policy::decide($local, $tg)->transport);
        self::assertSame(
            'caption_too_long',
            Policy::decide($local->withCaption(str_repeat('😀', 513)), $tg)->reason
        );
    }
    public function testDisabledMediaDoesNotBuildAndFallbackFailureIsNotRetried(): void
    {
        $http = new FakeClient([new \RuntimeException('sensitive transport error')]);
        $sender = new Sender(new Telegram(new Config('123:token'), $http), false);
        $built = false;
        try {
            $sender->send('1', 'text', function () use (&$built) {
                $built = true;
                throw new \RuntimeException();
            });
            self::fail();
        } catch (\diBot\Exception\ApiException $e) {
            self::assertSame('network_error', $e->reason);
        }
        self::assertFalse($built);
        self::assertCount(1, $http->requests);
    }
    public function testRefusedMediaAndFailedFactorySendFallbackOnlyOnce(): void
    {
        foreach (
            [
                fn() => new Media('document', 'application/pdf', 99999999, localPath: '/unused'),
                function () {
                    throw new \RuntimeException('SECRET');
                },
            ]
            as $factory
        ) {
            $http = new FakeClient([FakeClient::json(['ok' => true, 'result' => []])]);
            (new Sender(new Telegram(new Config('123:token'), $http)))->send(
                '1',
                'caption',
                $factory,
                fallbackText: 'fallback'
            );
            self::assertCount(1, $http->requests);
            self::assertSame('fallback', json_decode($http->requests[0]->body, true)['text']);
        }
    }
    public function testFailedMediaAndFailedFallbackMakeExactlyTwoCalls(): void
    {
        $http = new FakeClient([
            FakeClient::json(['code' => 'failure'], 400),
            FakeClient::json(['code' => 'failure'], 400),
        ]);
        try {
            (new Sender(new Max(new Config('token'), $http)))->send(
                '1',
                'text',
                fn() => new Media('photo', 'image/png', 1, 'https://example.com/image')
            );
            self::fail();
        } catch (\diBot\Exception\ApiException) {
            self::assertCount(2, $http->requests);
        }
    }
}
