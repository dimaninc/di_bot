<?php
namespace diBot\Instagram;

use diBot\Exception\SignatureException;

/**
 * Вебхук приложения Meta: один адрес на все подключённые аккаунты. Проверяет подпись и
 * режет тело на самостоятельные события; маршрутизация по entry_id – дело приложения.
 */
final class Webhook
{
    // Meta пакует несколько событий в одно тело.
    public const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __construct(
        #[\SensitiveParameter] private readonly string $appSecret,
        #[\SensitiveParameter] private readonly string $verifyToken
    ) {
        if ($appSecret === '' || $verifyToken === '') {
            throw new \InvalidArgumentException('Webhook secrets must not be empty');
        }
    }

    /**
     * Подтверждение адреса (GET): hub.challenge при совпадении токена, иначе null.
     * PHP превращает точки в именах параметров в подчёркивания, поэтому из $_GET приходит
     * hub_mode – принимаются оба написания.
     */
    public function challenge(array $query): ?string
    {
        $get = static function (string $name) use ($query): string {
            $value = $query['hub.' . $name] ?? ($query['hub_' . $name] ?? '');
            return is_string($value) ? $value : '';
        };
        $challenge = $get('challenge');
        // Ответ уходит телом страницы: отражаем только безопасные символы.
        if (
            $get('mode') !== 'subscribe' ||
            !hash_equals($this->verifyToken, $get('verify_token')) ||
            !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $challenge)
        ) {
            return null;
        }
        return $challenge;
    }

    /**
     * $signatureHeader – значение X-Hub-Signature-256 («sha256=<hex>»).
     * Каждое событие: object, entry_id, time и либо messaging (элемент entry.messaging),
     * либо change (элемент entry.changes) – его можно хранить строкой и разбирать
     * Platform::Instagram->parse() позже. Подпись не сошлась – SignatureException.
     *
     * @return list<array>
     */
    public function events(
        #[\SensitiveParameter] string $rawBody,
        #[\SensitiveParameter] string $signatureHeader
    ): array {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new \LengthException('Update too large');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->appSecret);
        if (!hash_equals($expected, strtolower(trim($signatureHeader)))) {
            throw new SignatureException();
        }
        try {
            $data = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data) || !is_array($data['entry'] ?? null)) {
            return [];
        }
        $object = $data['object'] ?? null;
        if ($object !== 'instagram') {
            return [];
        }
        $events = [];
        foreach ($data['entry'] as $entry) {
            $id = is_array($entry) ? $entry['id'] ?? null : null;
            // Без entry.id событие некуда направить: аккаунт-получатель неизвестен.
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            $base = [
                'object' => $object,
                'entry_id' => (string) $id,
                'time' => $entry['time'] ?? null,
            ];
            foreach (['messaging' => 'messaging', 'changes' => 'change'] as $list => $key) {
                foreach (is_array($entry[$list] ?? null) ? $entry[$list] : [] as $item) {
                    if (is_array($item)) {
                        $events[] = $base + [$key => $item];
                    }
                }
            }
        }
        return $events;
    }

    public function __debugInfo(): array
    {
        return ['appSecret' => '[redacted]', 'verifyToken' => '[redacted]'];
    }
}
