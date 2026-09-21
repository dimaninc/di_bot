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
        if ($request->publicUpload) {
            [$host, $ip] = UploadUrl::resolve($request->url);
            // Фиксируем проверенный DNS-ответ; прокси мог бы разрешить имя заново.
            $options[CURLOPT_PROXY] = '';
            $options[CURLOPT_RESOLVE] = [
                $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
            ];
        } elseif ($request->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $request->caBundle;
        }
        $body = '';
        $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) {
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
                throw new ApiException($status, 'network_error');
            }
            return new Response($status, $body);
        } finally {
            curl_close($ch);
        }
    }
}
