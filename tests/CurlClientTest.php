<?php
namespace diBot\Http {
    // Подменяется только граница cURL: проверяем фактические опции без сети и токенов.
    function curl_init()
    {
        return \curl_init();
    }
    function curl_setopt_array($handle, array $options): bool
    {
        if (\diBot\Tests\CurlProbe::$active) {
            \diBot\Tests\CurlProbe::$options = $options;
            return true;
        }
        return \curl_setopt_array($handle, $options);
    }
    function curl_exec($handle)
    {
        if (!\diBot\Tests\CurlProbe::$active) {
            return \curl_exec($handle);
        }
        if (\diBot\Tests\CurlProbe::$errno !== 0) {
            return false;
        }
        $header = \diBot\Tests\CurlProbe::$options[CURLOPT_HEADERFUNCTION];
        foreach (\diBot\Tests\CurlProbe::$headers as $line) {
            if ($header($handle, $line) !== strlen($line)) {
                return false;
            }
        }
        $writer = \diBot\Tests\CurlProbe::$options[CURLOPT_WRITEFUNCTION];
        foreach (\diBot\Tests\CurlProbe::$chunks as $chunk) {
            if ($writer($handle, $chunk) !== strlen($chunk)) {
                return false;
            }
        }
        return true;
    }
    function curl_getinfo($handle, int $option)
    {
        return \diBot\Tests\CurlProbe::$active ? 200 : \curl_getinfo($handle, $option);
    }
    function curl_errno($handle): int
    {
        return \diBot\Tests\CurlProbe::$active
            ? \diBot\Tests\CurlProbe::$errno
            : \curl_errno($handle);
    }
}
namespace diBot\Tests {
    final class CurlProbe
    {
        public static bool $active = false;
        public static array $options = [];
        public static array $headers = [];
        public static array $chunks = ['{"ok":true}'];
        public static int $errno = 0;
    }
    final class CurlClientTest extends \PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            CurlProbe::$active = true;
            CurlProbe::$options = [];
            CurlProbe::$headers = [];
            CurlProbe::$chunks = ['{"ok":true}'];
            CurlProbe::$errno = 0;
        }
        protected function tearDown(): void
        {
            CurlProbe::$active = false;
        }
        public function testTlsRedirectsTimeoutAndUploadDnsPinning(): void
        {
            $r = (new \diBot\Http\CurlClient())->send(
                new \diBot\Http\Request(
                    'POST',
                    'https://8.8.8.8/upload',
                    body: 'file',
                    timeout: 45,
                    connectTimeout: 7,
                    caBundle: '/untrusted',
                    publicUpload: true
                )
            );
            self::assertSame(200, $r->status);
            self::assertFalse(CurlProbe::$options[CURLOPT_FOLLOWLOCATION]);
            self::assertSame(CURLPROTO_HTTPS, CurlProbe::$options[CURLOPT_PROTOCOLS]);
            self::assertTrue(CurlProbe::$options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, CurlProbe::$options[CURLOPT_SSL_VERIFYHOST]);
            self::assertSame(45, CurlProbe::$options[CURLOPT_TIMEOUT]);
            self::assertSame(7, CurlProbe::$options[CURLOPT_CONNECTTIMEOUT]);
            self::assertSame(['8.8.8.8:443:8.8.8.8'], CurlProbe::$options[CURLOPT_RESOLVE]);
            self::assertSame('', CurlProbe::$options[CURLOPT_PROXY]);
            self::assertArrayNotHasKey(CURLOPT_CAINFO, CurlProbe::$options);
        }
        public function testApiTrustBundleAndResponseSizeBound(): void
        {
            (new \diBot\Http\CurlClient())->send(
                new \diBot\Http\Request('GET', 'https://api.example/', caBundle: '/selected-ca')
            );
            self::assertSame('/selected-ca', CurlProbe::$options[CURLOPT_CAINFO]);
            $writer = CurlProbe::$options[CURLOPT_WRITEFUNCTION];
            self::assertSame(0, $writer(null, str_repeat('x', 8 * 1024 * 1024)));
        }
        public function testDownloadCapAbortsDuringTransferWithoutTrustingContentLength(): void
        {
            CurlProbe::$headers = ["HTTP/2 200\r\n", "Content-Length: 1\r\n"];
            CurlProbe::$chunks = ['123', '456'];
            try {
                (new \diBot\Http\CurlClient())->send(
                    new \diBot\Http\Request(
                        'GET',
                        'https://8.8.8.8/file',
                        publicDownload: true,
                        maxResponseBytes: 5
                    )
                );
                self::fail();
            } catch (\diBot\Exception\ApiException $e) {
                self::assertSame('response_too_large', $e->reason);
            }
            self::assertSame(['8.8.8.8:443:8.8.8.8'], CurlProbe::$options[CURLOPT_RESOLVE]);
            self::assertSame('', CurlProbe::$options[CURLOPT_PROXY]);
        }
        public function testDownloadCapturesOnlyFinalHeadersAndAcceptsExactSizeLimit(): void
        {
            CurlProbe::$headers = [
                "HTTP/1.1 100 Continue\r\n",
                "Location: https://discard.example\r\n",
                "HTTP/2 200\r\n",
                "Content-Type: image/png\r\n",
                "Retry-After: 12\r\n",
                "Set-Cookie: SECRET\r\n",
            ];
            CurlProbe::$chunks = ['123', '45'];
            $r = (new \diBot\Http\CurlClient())->send(
                new \diBot\Http\Request(
                    'GET',
                    'https://8.8.8.8/file',
                    publicDownload: true,
                    maxResponseBytes: 5
                )
            );
            self::assertSame('12345', $r->body);
            self::assertSame(['content-type' => 'image/png', 'retry-after' => '12'], $r->headers);
        }
        public function testDownloadRejectsPrivateAddressBeforeOpeningConnection(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            (new \diBot\Http\CurlClient())->send(
                new \diBot\Http\Request('GET', 'https://127.0.0.1/file', publicDownload: true)
            );
        }
        public function testTlsErrorCodesKeepTheirDistinctMeaning(): void
        {
            foreach (
                [
                    CURLE_SSL_CACERT => 'tls_untrusted_ca',
                    CURLE_SSL_CACERT_BADFILE => 'tls_ca_file',
                    CURLE_SSL_CERTPROBLEM => 'tls_client_certificate',
                    CURLE_OPERATION_TIMEDOUT => 'network_error',
                ]
                as $errno => $reason
            ) {
                CurlProbe::$errno = $errno;
                try {
                    (new \diBot\Http\CurlClient())->send(
                        new \diBot\Http\Request('GET', 'https://api.example/')
                    );
                    self::fail();
                } catch (\diBot\Exception\ApiException $e) {
                    self::assertSame($reason, $e->reason);
                }
            }
        }
    }
}
