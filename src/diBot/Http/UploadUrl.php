<?php
namespace diBot\Http;

use diBot\Config;

final class UploadUrl
{
    public static function publicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // Только global unicast, без специальных протоколов и документальных сетей.
            if ((ord($packed[0]) & 0xe0) !== 0x20) {
                return false;
            }
            foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $range) {
                if (self::inRange($packed, $range)) {
                    return false;
                }
            }
            return true;
        }
        foreach (
            [
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ]
            as $range
        ) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }
        return true;
    }

    private static function inRange(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $network = inet_pton($network);
        $full = intdiv((int) $bits, 8);
        if (substr($packed, 0, $full) !== substr($network, 0, $full)) {
            return false;
        }
        $remaining = (int) $bits % 8;
        return $remaining === 0 ||
            ((ord($packed[$full]) ^ ord($network[$full])) & (0xff << 8 - $remaining)) === 0;
    }

    /** @return array{string,string} hostname and pinned IP */
    public static function resolve(string $url, ?\Closure $resolver = null): array
    {
        Config::assertHttpsUrl($url);
        $parts = parse_url($url);
        if (($parts['port'] ?? 443) !== 443) {
            throw new \InvalidArgumentException('Upload requires port 443');
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : ($resolver
                ? $resolver($host)
                : self::addresses($host));
        if (!$ips) {
            throw new \RuntimeException('Upload host has no addresses');
        }
        foreach ($ips as $ip) {
            if (!self::publicIp($ip)) {
                throw new \InvalidArgumentException('Upload host is not public');
            }
        }
        return [$host, $ips[0]];
    }

    private static function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return $ips;
    }
}
