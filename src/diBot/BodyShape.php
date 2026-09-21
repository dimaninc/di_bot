<?php
namespace diBot;

final class BodyShape
{
    // Только известные имена: произвольный ключ тоже может содержать пользовательский текст.
    private const KEYS = [
        'update_id',
        'update_type',
        'timestamp',
        'message',
        'callback_query',
        'callback',
        'body',
        'text',
        'caption',
        'chat',
        'chat_id',
        'user_id',
        'id',
        'from',
        'sender',
        'recipient',
        'user',
        'first_name',
        'last_name',
        'username',
        'language_code',
        'name',
        'data',
        'payload',
        'attachments',
        'reply_markup',
        'inline_keyboard',
        'buttons',
        'type',
        'url',
        'token',
        'file_id',
        'photo',
        'document',
        'message_id',
        'mid',
        'callback_id',
        'notification',
        'ok',
        'result',
        'code',
        'description',
        'updates',
        'marker',
    ];

    public static function describe(mixed $body, int $depth = 0): string
    {
        if (is_string($body)) {
            return 'string(' . strlen($body) . ' bytes)';
        }
        if (!is_array($body)) {
            return get_debug_type($body);
        }
        if ($depth >= 3) {
            return (array_is_list($body) ? 'list' : 'map') . '(' . count($body) . ')';
        }
        $parts = [];
        foreach (array_slice($body, 0, 20, true) as $key => $value) {
            $label = is_int($key) ? '#' : (in_array($key, self::KEYS, true) ? $key : '?');
            $parts[] = $label . ':' . self::describe($value, $depth + 1);
        }
        return '{' . implode(',', $parts) . (count($body) > 20 ? ',…' : '') . '}';
    }
}
