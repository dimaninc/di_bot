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
        $writer = \diBot\Tests\CurlProbe::$options[CURLOPT_WRITEFUNCTION];
        return $writer($handle, '{"ok":true}') > 0;
    }
    function curl_getinfo($handle, int $option)
    {
        return \diBot\Tests\CurlProbe::$active ? 200 : \curl_getinfo($handle, $option);
    }
}
namespace diBot\Tests {
    final class CurlProbe
    {
        public static bool $active = false;
        public static array $options = [];
    }
    final class CurlClientTest extends \PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            CurlProbe::$active = true;
            CurlProbe::$options = [];
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
    }
}
