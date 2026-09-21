<?php
namespace diBot\Http;

final class Multipart
{
    /** @return array{string,string} body, content type */
    public static function build(
        array $fields,
        string $field,
        string $filename,
        string $mime,
        string $bytes
    ): array {
        $boundary = 'diBot' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as $key => $value) {
            $body .=
                "--$boundary\r\nContent-Disposition: form-data; name=\"" .
                self::safe($key) .
                "\"\r\n\r\n";
            $body .=
                (is_array($value)
                    ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : (string) $value) . "\r\n";
        }
        $body .=
            "--$boundary\r\nContent-Disposition: form-data; name=\"" .
            self::safe($field) .
            '"; filename="' .
            self::safe($filename) .
            "\"\r\n";
        $body .=
            'Content-Type: ' . self::safe($mime) . "\r\n\r\n" . $bytes . "\r\n--$boundary--\r\n";
        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    private static function safe(string $value): string
    {
        return preg_replace('/[\x00-\x1f\x7f"\\\\]/', '_', $value);
    }
}
