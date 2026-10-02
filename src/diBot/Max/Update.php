<?php
namespace diBot\Max;

use diBot\AbstractUpdate;
use diBot\Platform;

final class Update extends AbstractUpdate
{
    public static function fromArray(array $data): ?self
    {
        $u = new self();
        $u->platform = Platform::Max;
        $u->raw = $data;
        $type = $data['update_type'] ?? '';
        $msg = is_array($data['message'] ?? null) ? $data['message'] : [];
        $body = is_array($msg['body'] ?? null) ? $msg['body'] : [];
        $recipient = is_array($msg['recipient'] ?? null) ? $msg['recipient'] : [];
        $u->messageId = self::string($body['mid'] ?? '');
        if ($type === 'bot_started' || $type === 'bot_stopped') {
            $timestamp = self::string($data['timestamp'] ?? '');
            // У старта/остановки нет message/callback ID; без timestamp дедупликация неоднозначна.
            if ($timestamp === '' || !ctype_digit($timestamp)) {
                return null;
            }
            $user = $data['user'] ?? [];
            $u->chatId = self::string($data['chat_id'] ?? '');
            $u->isPrivateChat = true;
            if ($type === 'bot_started') {
                // По справочнику MAX – «впервые начал общение или возобновил после остановки»,
                // то есть это /start, а не событие членства: membership не ставим. Иначе
                // обработчик, пропускающий события членства, терял бы каждый /start с
                // payload. Возврат после остановки на MAX виден только так.
                $u->text = '/start';
                $u->command = 'start';
                $u->commandPayload = self::string($data['payload'] ?? '');
            } else {
                $u->membership = self::MEMBERSHIP_STOPPED;
            }
        } elseif ($type === 'message_created' || $type === 'message_callback') {
            $callback = is_array($data['callback'] ?? null) ? $data['callback'] : [];
            $user = $type === 'message_callback' ? $callback['user'] ?? [] : $msg['sender'] ?? [];
            $u->chatId = self::string($recipient['chat_id'] ?? '');
            $u->isPrivateChat =
                ($recipient['chat_type'] ?? ($recipient['type'] ?? '')) === 'dialog';
            if ($type === 'message_callback') {
                $u->callbackId = self::string($callback['callback_id'] ?? '');
                $u->payload = self::string($callback['payload'] ?? '');
                if ($u->callbackId === '') {
                    return null;
                }
            } else {
                $u->text = self::string($body['text'] ?? '');
                $u->parseCommand();
                [$u->attachments, $other] = self::parseAttachments($body['attachments'] ?? []);
                foreach ($other as $kind) {
                    $u->addOtherContent($kind);
                }
                if ($u->messageId === '') {
                    return null;
                }
            }
        } else {
            return null;
        }
        if (!is_array($user)) {
            return null;
        }
        $u->userId = self::string($user['user_id'] ?? '');
        $u->profile($user);
        // Timestamp не уникален: разные сообщения могут прийти в одну миллисекунду.
        $identity =
            $u->callbackId ?:
            ($u->messageId ?:
            $u->chatId . ':' . $u->userId . ':' . self::string($data['timestamp'] ?? ''));
        $u->updateId = hash('sha256', $type . ':' . $identity);
        return $u->chatId !== '' && $u->userId !== '' ? $u : null;
    }

    /**
     * Один проход: image и file с токеном или ссылкой – в attachments, остальное – виды для
     * otherContent (так не держим два списка «обрабатываемых» типов). image/file без токена и
     * ссылки – other: иначе сообщение пришло бы пустым. Отдельного типа voice в схеме MAX
     * нет: голосовое, вероятно, приходит как audio (живьём не проверено), поэтому audio не
     * переименовываем. share и неизвестные – other.
     *
     * @return array{0: list<\diBot\Attachment\Attachment>, 1: list<string>}
     */
    private static function parseAttachments(mixed $attachments): array
    {
        $result = [];
        $other = [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            $type = is_array($attachment) ? $attachment['type'] ?? null : null;
            // Битый элемент (не объект, без строкового type) – не содержимое собеседника.
            if (!is_string($type) || $type === 'inline_keyboard') {
                continue;
            }
            if ($type !== 'image' && $type !== 'file') {
                $known = ['audio', 'video', 'sticker', 'location', 'contact'];
                $other[] = in_array($type, $known, true) ? $type : 'other';
                continue;
            }
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            $ref =
                self::string($payload['token'] ?? '') ?: self::string($payload['photo_id'] ?? '');
            $url = self::string($payload['url'] ?? '');
            if ($ref === '' && $url === '') {
                $other[] = 'other';
                continue;
            }
            $name =
                self::string($attachment['filename'] ?? '') ?:
                self::string($payload['filename'] ?? '');
            $size = is_int($attachment['size'] ?? null) ? max(0, $attachment['size']) : 0;
            if (!$size) {
                $size = is_int($payload['size'] ?? null) ? max(0, $payload['size']) : 0;
            }
            $result[] = new \diBot\Attachment\Attachment(
                $type === 'image' ? 'photo' : \diBot\Attachment\KindResolver::resolve('', $name),
                $ref,
                $url,
                size: $size,
                filename: $name
            );
        }
        return [$result, $other];
    }
}
