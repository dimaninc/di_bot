<?php
namespace diBot;

readonly final class Keyboard
{
    /** @param list<list<Button>> $rows */
    public function __construct(public array $rows = [])
    {
    }

    /**
     * Instagram: рядов нет, кнопки идут списком. До TEMPLATE_BUTTONS_MAX кнопок –
     * ['buttons' => …] для шаблона button (callback – postback, link – web_url); больше –
     * ['quick_replies' => …], только из callback; пустая клавиатура – [].
     */
    public function render(Platform $platform): array
    {
        $class = match ($platform) {
            Platform::Telegram => Telegram\BotApi::class,
            Platform::Max => Max\BotApi::class,
            Platform::Instagram => Instagram\BotApi::class,
        };
        if ($platform === Platform::Instagram) {
            return $this->instagram();
        }
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
                $text = self::label($button, $class);
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

    private function instagram(): array
    {
        $class = Instagram\BotApi::class;
        $buttons = [];
        foreach ($this->rows as $row) {
            foreach ($row as $button) {
                $buttons[] = [$button, self::label($button, $class)];
            }
        }
        if ($buttons === []) {
            return [];
        }
        if (count($buttons) <= $class::TEMPLATE_BUTTONS_MAX) {
            $native = [];
            foreach ($buttons as [$button, $title]) {
                $native[] =
                    $button->type === 'link'
                        ? ['type' => 'web_url', 'url' => $button->value, 'title' => $title]
                        : ['type' => 'postback', 'title' => $title, 'payload' => $button->value];
            }
            return ['buttons' => $native];
        }
        if (count($buttons) > $class::BUTTONS_MAX) {
            throw new \InvalidArgumentException(
                'Too many buttons; paginate at the application layer'
            );
        }
        $native = [];
        foreach ($buttons as [$button, $title]) {
            // Ссылку quick reply не несёт: молча выбросить кнопку нельзя.
            if ($button->type === 'link') {
                throw new \InvalidArgumentException(
                    'Instagram allows link buttons only in keyboards of up to 3 buttons'
                );
            }
            $native[] = ['content_type' => 'text', 'title' => $title, 'payload' => $button->value];
        }
        return ['quick_replies' => $native];
    }

    /** Проверка кнопки и подпись, обрезанная по лимиту площадки. */
    private static function label(mixed $button, string $class): string
    {
        if (!($button instanceof Button)) {
            throw new \InvalidArgumentException('Expected Button');
        }
        if (
            $button->type === 'callback' &&
            (strlen($button->value) < 1 || strlen($button->value) > $class::CALLBACK_MAX_BYTES)
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
        return $text === '' ? '…' : $text;
    }
}
