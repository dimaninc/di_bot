<?php
namespace diBot\Tests;

use diBot\Attachment\KindResolver;
use diBot\Config;
use diBot\Exception\{ApiException, DownloadException};
use diBot\Http\Response;
use diBot\Telegram\{BotApi as Telegram, Update as TelegramUpdate};
use diBot\Max\{BotApi as Max, Update as MaxUpdate};
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    public function testTelegramPhotoSelectionDocumentsAndCallbacks(): void
    {
        $raw = UpdateTest::telegram();
        $raw['message']['photo'] = [
            ['file_id' => 'large', 'width' => 100, 'height' => 100, 'file_size' => 30],
            ['file_id' => 'small', 'width' => 10, 'height' => 10],
        ];
        $raw['message']['document'] = [
            'file_id' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_name' => 'receipt.pdf',
            'file_size' => 42,
        ];
        $items = TelegramUpdate::fromArray($raw)->attachments;
        self::assertCount(2, $items);
        self::assertSame('large', $items[0]->ref);
        self::assertSame(30, $items[0]->size);
        self::assertSame('document', $items[1]->kind);
        self::assertSame('receipt.pdf', $items[1]->filename);
        $raw['callback_query'] = [
            'id' => 'callback',
            'data' => 'go',
            'from' => $raw['message']['from'],
            'message' => $raw['message'],
        ];
        self::assertSame([], TelegramUpdate::fromArray($raw)->attachments);
    }
    public function testMaxMetadataFallbackAndMalformedAttachments(): void
    {
        $raw = UpdateTest::max();
        $raw['message']['body']['attachments'] = [
            null,
            'bad',
            ['type' => 'inline_keyboard'],
            [
                'type' => 'image',
                'payload' => ['photo_id' => 123, 'url' => 'https://cdn.example/photo'],
            ],
            [
                'type' => 'file',
                'filename' => '',
                'size' => 0,
                'payload' => [
                    'token' => 'file',
                    'url' => 'https://cdn.example/file',
                    'filename' => 'scan.PNG',
                    'size' => 33,
                ],
            ],
            ['type' => 'file', 'payload' => ['token' => 'pdf', 'filename' => 'receipt.pdf']],
        ];
        $items = MaxUpdate::fromArray($raw)->attachments;
        self::assertCount(3, $items);
        self::assertSame('123', $items[0]->ref);
        self::assertSame('photo', $items[0]->kind);
        // Картинка, отправленная файлом, – документ: вид как отправлено, а не по расширению.
        self::assertSame('document', $items[1]->kind);
        self::assertSame('scan.PNG', $items[1]->filename);
        self::assertSame(33, $items[1]->size);
        self::assertSame('document', $items[2]->kind);
    }
    public function testKindResolverDescribesByMimeOrExtension(): void
    {
        self::assertSame('photo', KindResolver::resolve('image/heic'));
        self::assertSame('photo', KindResolver::resolve('Image/PNG; charset=binary'));
        self::assertSame('photo', KindResolver::resolve('application/octet-stream', 'x.TIFF'));
        self::assertSame('photo', KindResolver::resolve('', 'scan.jpeg'));
        self::assertSame('document', KindResolver::resolve('application/pdf', 'x.png'));
        self::assertSame('document', KindResolver::resolve('', 'notes.txt'));
        self::assertSame('document', KindResolver::resolve(''));
    }
    public function testTelegramDownloadsViaConfiguredProxyWithBoundedBinaryRequest(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => ['file_path' => 'documents/file.pdf']]),
            new Response(200, "pdf\0bytes", ['content-type' => 'Application/PDF; charset=binary']),
        ]);
        $api = new Telegram(
            new Config(
                '123:token',
                'https://proxy.example',
                proxySecret: 'proxy',
                maxDownloadBytes: 20
            ),
            $http
        );
        $file = $api->downloadAttachment('ref');
        self::assertSame("pdf\0bytes", $file->bytes);
        self::assertSame('application/pdf', $file->mime);
        self::assertStringEndsWith('/getFile', $http->requests[0]->url);
        self::assertSame(['file_id' => 'ref'], json_decode($http->requests[0]->body, true));
        $r = $http->requests[1];
        self::assertSame('https://proxy.example/file/bot123:token/documents/file.pdf', $r->url);
        self::assertContains('X-Proxy-Auth: proxy', $r->headers);
        self::assertSame('GET', $r->method);
        self::assertSame(20, $r->maxResponseBytes);
        self::assertFalse($r->publicDownload);
    }
    public function testTelegramDefaultHostDoesNotReceiveProxySecret(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => ['file_path' => 'documents/file']]),
            new Response(200, 'file'),
        ]);
        (new Telegram(new Config('123:token', proxySecret: 'secret'), $http))->downloadAttachment(
            'ref'
        );
        self::assertSame([], $http->requests[1]->headers);
    }
    public function testTelegramRefusesPathTraversalAndEarlySizeLimit(): void
    {
        foreach (
            [
                '../x.pdf',
                '/file.pdf',
                'x/../file.pdf',
                'https://evil.example/x',
                'x//file.pdf',
                "x\\file.pdf",
            ]
            as $path
        ) {
            $http = new FakeClient([
                FakeClient::json(['ok' => true, 'result' => ['file_path' => $path]]),
            ]);
            try {
                (new Telegram(new Config('token'), $http))->downloadAttachment('ref');
                self::fail($path);
            } catch (DownloadException $e) {
                self::assertTrue($e->permanent);
                self::assertSame('invalid_file_path', $e->reason);
            }
            self::assertCount(1, $http->requests);
        }
        $http = new FakeClient([
            FakeClient::json([
                'ok' => true,
                'result' => ['file_path' => 'file.pdf', 'file_size' => 10],
            ]),
        ]);
        try {
            (new Telegram(new Config('token', maxDownloadBytes: 5), $http))->downloadAttachment(
                'ref'
            );
            self::fail();
        } catch (DownloadException $e) {
            self::assertSame('too_big', $e->reason);
        }
        self::assertCount(1, $http->requests);
    }
    public function testTelegramDownloadDoesNotForwardSecretsOnRedirect(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => ['file_path' => 'file.pdf']]),
            new Response(302, '', ['location' => 'https://other.example/file']),
        ]);
        try {
            (new Telegram(
                new Config('token', 'https://proxy.example', proxySecret: 'secret'),
                $http
            ))->downloadAttachment('ref');
            self::fail();
        } catch (DownloadException $e) {
            self::assertSame('redirect_refused', $e->reason);
        }
        self::assertCount(2, $http->requests);
    }
    public function testMaxDownloadsSignedCdnUrlWithoutTokenOrCaOnEveryHop(): void
    {
        $http = new FakeClient([
            new Response(302, '', ['location' => 'https://other.example/file?signature=PRIVATE']),
            new Response(200, 'file', ['content-type' => 'image/png']),
        ]);
        $file = (new Max(new Config('PRIVATE_TOKEN'), $http))->downloadAttachment(
            'ref',
            'https://cdn.example/file'
        );
        self::assertSame('image/png', $file->mime);
        foreach ($http->requests as $r) {
            self::assertSame([], $r->headers);
            self::assertNull($r->caBundle);
            self::assertTrue($r->publicDownload);
        }
        self::assertStringNotContainsString('PRIVATE', print_r($http->requests[1], true));
    }
    public function testMaxRedirectCannotReachPrivateOrNonHttpsUrl(): void
    {
        foreach (
            [
                'http://cdn.example/file',
                'https://127.0.0.1/file',
                'https://user:pass@example.com/file',
                'https://[::1]/file',
                '/relative',
            ]
            as $url
        ) {
            $http = new FakeClient([new Response(302, '', ['location' => $url])]);
            try {
                (new Max(new Config('token'), $http))->downloadAttachment(
                    'ref',
                    'https://cdn.example/file'
                );
                self::fail();
            } catch (DownloadException $e) {
                self::assertSame('blocked_url', $e->reason);
                self::assertTrue($e->permanent);
            }
            self::assertCount(1, $http->requests);
        }
    }
    public function testRedirectLoopHasBoundedNumberOfRequests(): void
    {
        $http = new FakeClient(
            array_fill(0, 4, new Response(302, '', ['location' => 'https://cdn.example/file']))
        );
        try {
            (new Max(new Config('token'), $http))->downloadAttachment(
                'ref',
                'https://cdn.example/file'
            );
            self::fail();
        } catch (DownloadException $e) {
            self::assertSame('too_many_redirects', $e->reason);
        }
        self::assertCount(4, $http->requests);
    }
    public function testDownloadErrorsAreClassifiedAndDoNotLeakUrlOrBody(): void
    {
        foreach (
            [
                [new Response(404, 'PRIVATE'), true, 'download_failed'],
                [new Response(206, 'piece'), false, 'incomplete_download'],
                [new Response(500, 'PRIVATE'), false, 'download_failed'],
                [new Response(200, ''), false, 'empty_download'],
                [new Response(200, 'TOO_BIG'), true, 'too_big'],
                [
                    new \RuntimeException('https://cdn.example/?secret=PRIVATE'),
                    false,
                    'network_error',
                ],
                [new ApiException(0, 'response_too_large'), true, 'too_big'],
                [new ApiException(0, 'tls_untrusted_ca'), true, 'tls_untrusted_ca'],
            ]
            as [$response, $permanent, $reason]
        ) {
            try {
                (new Max(
                    new Config('token', maxDownloadBytes: 5),
                    new FakeClient([$response])
                ))->downloadAttachment('ref', 'https://cdn.example/?secret=PRIVATE');
                self::fail();
            } catch (DownloadException $e) {
                self::assertSame($permanent, $e->permanent);
                self::assertSame($reason, $e->reason);
                self::assertStringNotContainsString('PRIVATE', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }
    public function testDownloadRateLimitSurvivesGetFileAndCdnErrors(): void
    {
        $tg = new Telegram(
            new Config('token'),
            new FakeClient([
                FakeClient::json(
                    ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 9]],
                    429
                ),
            ])
        );
        $max = new Max(
            new Config('token'),
            new FakeClient([new Response(429, '', ['retry-after' => '11'])])
        );
        foreach ([[$tg, 9], [$max, 11]] as [$api, $delay]) {
            try {
                $api->downloadAttachment('ref', 'https://cdn.example/file');
                self::fail();
            } catch (DownloadException $e) {
                self::assertFalse($e->permanent);
                self::assertSame($delay, $e->rateLimitDelay());
            }
        }
    }
}
