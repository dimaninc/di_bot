<?php
namespace diBot;

final class Text
{
    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function truncate(string $text, int $units): string
    {
        $result = '';
        foreach (mb_str_split(mb_scrub($text, 'UTF-8'), 1, 'UTF-8') as $char) {
            $size = self::length($char);
            if ($size > $units) {
                break;
            }
            $result .= $char;
            $units -= $size;
        }
        return $result;
    }
}
