<?php
namespace diBot;

readonly final class Button
{
    private function __construct(public string $text, public string $type, public string $value)
    {
    }

    public static function callback(string $text, string $payload): self
    {
        return new self($text, 'callback', $payload);
    }

    public static function link(string $text, string $url): self
    {
        return new self($text, 'link', $url);
    }

    public function marked(string $role): self
    {
        try {
            $text = trim($this->text);
            $point = $text === '' ? 0 : mb_ord($text, 'UTF-8');
            // Диапазоны вместо Extended_Pictographic: свойство есть не во всех PCRE2.
            foreach (
                [
                    [0xa9, 0xa9],
                    [0xae, 0xae],
                    [0x203c, 0x2049],
                    [0x2122, 0x2139],
                    [0x2194, 0x21aa],
                    [0x231a, 0x23fa],
                    [0x24c2, 0x24c2],
                    [0x25aa, 0x27bf],
                    [0x2934, 0x2935],
                    [0x2b00, 0x2bff],
                    [0x3030, 0x303d],
                    [0x3297, 0x3299],
                    [0x1f000, 0x1faff],
                ]
                as [$from, $to]
            ) {
                if ($point >= $from && $point <= $to) {
                    return $this;
                }
            }
            $prefix = ['positive' => '✅ ', 'negative' => '✖️ '][$role] ?? '';
            return new self($prefix . $text, $this->type, $this->value);
        } catch (\Throwable) {
            return $this;
        }
    }
}
