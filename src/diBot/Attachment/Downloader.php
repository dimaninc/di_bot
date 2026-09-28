<?php
namespace diBot\Attachment;

use diBot\Config;
use diBot\Exception\{ApiException, DownloadException};
use diBot\Http\{Client, Request, UploadUrl};

final class Downloader
{
    public function __construct(private Client $http, private Config $config)
    {
    }

    /** Для публичных CDN каждый redirect проверяется заново; секретные заголовки запрещены. */
    public function download(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        bool $publicUrl = true
    ): Download {
        if ($publicUrl && $headers !== []) {
            throw new \InvalidArgumentException('Public downloads cannot carry headers');
        }
        for ($hop = 0; $hop <= 3; $hop++) {
            try {
                Config::assertHttpsUrl($url);
                if ($publicUrl) {
                    $parts = parse_url($url);
                    $host = trim($parts['host'], '[]');
                    if (
                        ($parts['port'] ?? 443) !== 443 ||
                        (filter_var($host, FILTER_VALIDATE_IP) && !UploadUrl::publicIp($host))
                    ) {
                        throw new \InvalidArgumentException('Non-public download URL');
                    }
                }
                $response = $this->http->send(
                    new Request(
                        'GET',
                        $url,
                        $headers,
                        timeout: $this->config->downloadTimeout,
                        connectTimeout: $this->config->connectTimeout,
                        publicDownload: $publicUrl,
                        maxResponseBytes: $this->config->maxDownloadBytes
                    )
                );
            } catch (\InvalidArgumentException) {
                throw new DownloadException(0, 'blocked_url', true);
            } catch (ApiException $e) {
                // Не переносим чужой текст исключения или URL с подписью.
                $reason = match ($e->reason) {
                    'response_too_large' => 'too_big',
                    'tls_untrusted_ca' => 'tls_untrusted_ca',
                    'tls_ca_file' => 'tls_ca_file',
                    'tls_client_certificate' => 'tls_client_certificate',
                    default => 'network_error',
                };
                throw new DownloadException($e->httpStatus, $reason, $reason !== 'network_error');
            } catch (\Throwable) {
                throw new DownloadException(0, 'network_error', false);
            }
            $status = $response->status;
            if ($status >= 300 && $status < 400) {
                if (
                    !in_array($status, [301, 302, 303, 307, 308], true) ||
                    empty($response->headers['location'])
                ) {
                    throw new DownloadException($status, 'invalid_redirect', false);
                }
                if (!$publicUrl) {
                    throw new DownloadException($status, 'redirect_refused', false);
                }
                if ($hop === 3) {
                    throw new DownloadException($status, 'too_many_redirects', true);
                }
                $url = $response->headers['location'] ?? '';
                continue;
            }
            if ($status < 200 || $status >= 300 || $status === 206) {
                throw new DownloadException(
                    $status,
                    $status === 429
                        ? 'rate_limit'
                        : ($status === 206
                            ? 'incomplete_download'
                            : 'download_failed'),
                    $publicUrl && in_array($status, [403, 404, 410], true),
                    $status === 429
                        ? ApiException::retrySeconds($response->headers['retry-after'] ?? null)
                        : 0
                );
            }
            // Повторная проверка обязательна и для подменяемого HTTP-клиента.
            if (strlen($response->body) > $this->config->maxDownloadBytes) {
                throw new DownloadException($status, 'too_big', true);
            }
            if ($response->body === '') {
                throw new DownloadException($status, 'empty_download', false);
            }
            $mime = strtolower(trim(explode(';', $response->headers['content-type'] ?? '')[0]));
            if (!preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D', $mime)) {
                $mime = '';
            }
            return new Download($response->body, $mime);
        }
        throw new \LogicException('Unreachable download state');
    }
}
