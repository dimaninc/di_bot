<?php
// Наблюдаем опции и адрес соединения, передавая все вызовы настоящему libcurl.
namespace diBot\Http {
    function curl_setopt_array($handle, array $options): bool
    {
        \CurlSmoke::$options = $options;
        return \curl_setopt_array($handle, $options);
    }
    function curl_getinfo($handle, int $option)
    {
        \CurlSmoke::$peer = \curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        return \curl_getinfo($handle, $option);
    }
}
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    final class CurlSmoke
    {
        public static array $options = [];
        public static string $peer = '';
    }
    // Нерабочий прокси должен игнорироваться при обращении к публичному CDN.
    $variables = ['https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
    $original = [];
    foreach ($variables as $name) {
        $original[$name] = getenv($name);
        putenv(
            $name . '=' . (str_contains(strtolower($name), 'no_proxy') ? '' : 'http://127.0.0.1:1')
        );
    }
    try {
        foreach (['fu.oneme.ru', 'iu.oneme.ru'] as $host) {
            $response = (new \diBot\Http\CurlClient())->send(
                new \diBot\Http\Request(
                    'HEAD',
                    'https://' . $host . '/',
                    timeout: 15,
                    connectTimeout: 5,
                    publicDownload: true
                )
            );
            $entry = CurlSmoke::$options[CURLOPT_RESOLVE][0] ?? '';
            $pinned = trim(substr($entry, strlen($host . ':443:')), '[]');
            if (
                $response->status < 100 ||
                @inet_pton($pinned) !== @inet_pton(CurlSmoke::$peer) ||
                CurlSmoke::$peer === '' ||
                (CurlSmoke::$options[CURLOPT_PROXY] ?? null) !== ''
            ) {
                throw new \RuntimeException('DNS pinning/proxy smoke failed');
            }
            printf(
                "%s: HTTP %d, TLS verified, peer matches pinned IP, environment proxy bypassed\n",
                $host,
                $response->status
            );
        }
    } finally {
        foreach ($original as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }
}
