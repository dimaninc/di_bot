<?php
namespace diBot;

readonly final class Keyboard
{
    /** @param list<list<Button>> $rows */
    public function __construct(public array $rows = [])
    {
    }

    public function render(Platform $platform): array
    {
        $class = match ($platform) {
            Platform::Telegram => Telegram\BotApi::class,
            Platform::Max => Max\BotApi::class,
            default => throw new \LogicException('Unsupported keyboard platform'),
        };
        $rows = [];
        $total = 0;
        foreach ($this->rows as $row) {
            $rowLimit = $class::BUTTONS_PER_ROW;
            if ($platform === Platform::Max) {
                foreach ($row as $button) {
                    if ($button instanceof Button && $button->type === 'link') {
                        $rowLimit = 3;
                    }
                }
            }
            $native = [];
            foreach ($row as $button) {
                if (!($button instanceof Button)) {
                    throw new \InvalidArgumentException('Expected Button');
                }
                if (
                    $button->type === 'callback' &&
                    (strlen($button->value) < 1 ||
                        strlen($button->value) > $class::CALLBACK_MAX_BYTES)
                ) {
                    throw new \InvalidArgumentException('Callback payload length is out of range');
                }
                if ($button->type === 'link') {
                    Config::assertHttpsUrl($button->value);
                    if (strlen($button->value) > 2048) {
                        throw new \InvalidArgumentException('Button URL is too long');
                    }
                }
                $text = Text::truncate($button->text, $class::BUTTON_TEXT_MAX_UNITS);
                if ($text === '') {
                    $text = '…';
                }
                $native[] =
                    $platform === Platform::Telegram
                        ? [
                            'text' => $text,
                            $button->type === 'link' ? 'url' : 'callback_data' => $button->value,
                        ]
                        : [
                            'type' => $button->type,
                            'text' => $text,
                            $button->type === 'link' ? 'url' : 'payload' => $button->value,
                        ];
                if (++$total > $class::BUTTONS_MAX) {
                    throw new \InvalidArgumentException(
                        'Too many buttons; paginate at the application layer'
                    );
                }
            }
            foreach (array_chunk($native, $rowLimit) as $chunk) {
                $rows[] = $chunk;
            }
        }
        if (count($rows) > $class::ROWS_MAX) {
            throw new \InvalidArgumentException('Too many keyboard rows');
        }
        return $platform === Platform::Telegram
            ? ['inline_keyboard' => $rows]
            : ['type' => 'inline_keyboard', 'payload' => ['buttons' => $rows]];
    }
}
