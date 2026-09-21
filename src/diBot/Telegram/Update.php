<?php
namespace diBot\Telegram;

use diBot\AbstractUpdate;
use diBot\Platform;

final class Update extends AbstractUpdate
{
    public static function fromArray(array $data): ?self
    {
        $u = new self();
        $u->platform = Platform::Telegram;
        $u->updateId = self::string($data['update_id'] ?? '');
        $callback = $data['callback_query'] ?? null;
        $msg = is_array($callback) ? $callback['message'] ?? null : $data['message'] ?? null;
        if (!is_array($msg)) {
            return null;
        }
        $from = is_array($callback) ? $callback['from'] ?? [] : $msg['from'] ?? [];
        $chat = $msg['chat'] ?? [];
        if (!is_array($from) || !is_array($chat)) {
            return null;
        }
        $u->chatId = self::string($chat['id'] ?? '');
        $u->userId = self::string($from['id'] ?? '');
        $u->messageId = self::string($msg['message_id'] ?? '');
        $u->isPrivateChat = ($chat['type'] ?? '') === 'private';
        $u->profile($from);
        if (is_array($callback)) {
            $u->callbackId = self::string($callback['id'] ?? '');
            $u->payload = self::string($callback['data'] ?? '');
            if ($u->callbackId === '') {
                return null;
            }
        } else {
            $u->text = self::string($msg['text'] ?? ($msg['caption'] ?? ''));
            $u->parseCommand();
        }
        return $u->updateId !== '' && $u->chatId !== '' && $u->userId !== '' ? $u : null;
    }
}
