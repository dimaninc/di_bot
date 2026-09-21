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
        $type = $data['update_type'] ?? '';
        $msg = is_array($data['message'] ?? null) ? $data['message'] : [];
        $body = is_array($msg['body'] ?? null) ? $msg['body'] : [];
        $recipient = is_array($msg['recipient'] ?? null) ? $msg['recipient'] : [];
        $u->messageId = self::string($body['mid'] ?? '');
        if ($type === 'bot_started') {
            $user = $data['user'] ?? [];
            $u->chatId = self::string($data['chat_id'] ?? '');
            $u->isPrivateChat = true;
            $u->text = '/start';
            $u->command = 'start';
            $u->commandPayload = self::string($data['payload'] ?? '');
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
}
