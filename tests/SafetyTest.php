<?php
namespace diBot\Tests;
use diBot\{BodyShape, Config};
use diBot\Http\UploadUrl;
use PHPUnit\Framework\TestCase;

final class SafetyTest extends TestCase
{
    public function testBodyShapeContainsNoUserValuesOrUnknownKeys(): void
    {
        $shape = BodyShape::describe([
            'text' => 'private message',
            'token' => 'secret-token',
            'SECRET-KEY' => 'value',
            'message' => [
                'id' => 987654,
                'attachments' => array_fill(0, 100, ['type' => 'private-type']),
            ],
        ]);
        foreach (
            ['private message', 'secret-token', 'SECRET-KEY', '987654', 'private-type']
            as $secret
        ) {
            self::assertStringNotContainsString($secret, $shape);
        }
        self::assertStringContainsString('text:string(15 bytes)', $shape);
        self::assertLessThan(500, strlen($shape));
        $recursive = [];
        $recursive['message'] = &$recursive;
        self::assertLessThan(100, strlen(BodyShape::describe($recursive)));
    }
    public function testPrivateAndSpecialUploadAddressesAreRejected(): void
    {
        foreach (
            [
                '127.0.0.1',
                '10.0.0.1',
                '169.254.169.254',
                '100.64.0.1',
                '198.18.0.1',
                '192.0.2.1',
                '224.0.0.1',
                '::1',
                '::ffff:127.0.0.1',
                'fc00::1',
                'fe80::1',
                '2001:db8::1',
                '2002:7f00:1::',
                '2001:0:1::',
            ]
            as $ip
        ) {
            self::assertFalse(UploadUrl::publicIp($ip), $ip);
        }
        self::assertTrue(UploadUrl::publicIp('8.8.8.8'));
        self::assertTrue(UploadUrl::publicIp('2001:4860:4860::8888'));
        self::assertSame(
            ['cdn.example', '8.8.8.8'],
            UploadUrl::resolve('https://cdn.example/upload', fn() => ['8.8.8.8'])
        );
        $this->expectException(\InvalidArgumentException::class);
        UploadUrl::resolve('https://cdn.example/upload', fn() => ['8.8.8.8', '127.0.0.1']);
    }
    public function testUrlsCredentialsProtocolsAndPortsAreRejected(): void
    {
        foreach (
            [
                'http://example.com/file',
                'file:///etc/passwd',
                'https://name:secret@example.com',
                'https://example.com:8443/file',
                "https://example.com/\r\nx",
            ]
            as $url
        ) {
            try {
                UploadUrl::resolve($url, fn() => ['8.8.8.8']);
                self::fail($url);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testDebuggingDoesNotExposeSecrets(): void
    {
        $config = new Config(
            'token-secret',
            webhookSecret: 'hook-secret',
            proxySecret: 'proxy-secret'
        );
        $dump = print_r($config, true);
        foreach (['token-secret', 'hook-secret', 'proxy-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        $this->expectException(\InvalidArgumentException::class);
        new Config("token\r\nHeader: injected");
    }
    public function testCertificateIdentitiesAndExpiry(): void
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            file_get_contents(\diBot\Max\BotApi::defaultCaBundle()),
            $matches
        );
        $expected = [
            'Russian Trusted Root CA' =>
                'd26d2d0231b7c39f92cc738512ba54103519e4405d68b5bd703e9788ca8ecf31',
            'ISRG Root X1' => '96bcec06264976f37460779acf28c5a7cfe8a3c0aae11a8ffcee05c0bddf08c6',
            'ISRG Root X2' => '69729b8e15a86efc177a57afb7171dfc64add28c2fca8cf1507e34453ccb1470',
        ];
        self::assertCount(3, $matches[0]);
        foreach ($matches[0] as $pem) {
            $cert = openssl_x509_parse($pem);
            $name = $cert['subject']['CN'];
            self::assertArrayHasKey($name, $expected);
            self::assertSame($expected[$name], openssl_x509_fingerprint($pem, 'sha256'));
            self::assertGreaterThan(
                time() + 90 * 86400,
                $cert['validTo_time_t'],
                $name . ' expires in less than 90 days'
            );
            self::assertLessThanOrEqual(time(), $cert['validFrom_time_t']);
            self::assertStringContainsString('CA:TRUE', $cert['extensions']['basicConstraints']);
            self::assertSame(1, openssl_x509_verify($pem, openssl_pkey_get_public($pem)));
        }
    }
}
