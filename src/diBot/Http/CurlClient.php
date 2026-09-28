<?php
namespace diBot\Http;

use diBot\Config;
use diBot\Exception\ApiException;

final class CurlClient implements Client
{
    public function send(Request $request): Response
    {
        Config::assertHttpsUrl($request->url);
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $request->headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeout,
            CURLOPT_TIMEOUT => $request->timeout,
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($request->publicUpload || $request->publicDownload) {
            [$host, $ip] = UploadUrl::resolve($request->url);
            // Фиксируем проверенный DNS-ответ; прокси мог бы разрешить имя заново.
            $options[CURLOPT_PROXY] = '';
            $options[CURLOPT_RESOLVE] = [
                $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
            ];
        } elseif ($request->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $request->caBundle;
        }
        $headers = [];
        $headerBytes = 0;
        $tooLarge = false;
        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $line) use (
            &$headers,
            &$headerBytes,
            &$tooLarge
        ): int {
            $headerBytes += strlen($line);
            if ($headerBytes > 65536) {
                $tooLarge = true;
                return 0;
            }
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
            }
            $pair = explode(':', $line, 2);
            if (
                count($pair) === 2 &&
                in_array(
                    strtolower(trim($pair[0])),
                    ['content-type', 'location', 'retry-after'],
                    true
                )
            ) {
                $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
            return strlen($line);
        };
        $body = '';
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (
            &$body,
            &$tooLarge,
            $request
        ): int {
            if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
        $ch = curl_init();
        if ($ch === false) {
            throw new ApiException(0, 'curl_init');
        }
        try {
            curl_setopt_array($ch, $options);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) {
                throw new ApiException(
                    $status,
                    $tooLarge
                        ? 'response_too_large'
                        : match (curl_errno($ch)) {
                            CURLE_SSL_CACERT => 'tls_untrusted_ca',
                            CURLE_SSL_CACERT_BADFILE => 'tls_ca_file',
                            CURLE_SSL_CERTPROBLEM => 'tls_client_certificate',
                            default => 'network_error',
                        }
                );
            }
            return new Response($status, $body, $headers);
        } finally {
            curl_close($ch);
        }
    }
}
