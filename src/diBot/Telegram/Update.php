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
            $u->attachments = self::parseAttachments($msg);
        }
        return $u->updateId !== '' && $u->chatId !== '' && $u->userId !== '' ? $u : null;
    }
    private static function parseAttachments(array $message): array
    {
        $result = [];
        $photos = is_array($message['photo'] ?? null) ? $message['photo'] : [];
        $largest = null;
        $area = -1;
        foreach ($photos as $photo) {
            if (!is_array($photo) || self::string($photo['file_id'] ?? '') === '') {
                continue;
            }
            $w = is_int($photo['width'] ?? null) ? max(0, $photo['width']) : 0;
            $h = is_int($photo['height'] ?? null) ? max(0, $photo['height']) : 0;
            if ($w * $h >= $area) {
                $largest = $photo;
                $area = $w * $h;
            }
        }
        if ($largest !== null) {
            $result[] = self::attachment($largest, 'photo', 'image/jpeg');
        }
        $document = $message['document'] ?? null;
        if (
            is_array($document) &&
            self::string($document['file_id'] ?? '') !== '' &&
            !is_array($message['animation'] ?? null)
        ) {
            $mime = self::string($document['mime_type'] ?? '');
            $name = self::string($document['file_name'] ?? '');
            $result[] = self::attachment(
                $document,
                \diBot\Attachment\KindResolver::resolve($mime, $name),
                $mime
            );
        }
        return $result;
    }

    private static function attachment(
        array $file,
        string $kind,
        string $mime
    ): \diBot\Attachment\Attachment {
        return new \diBot\Attachment\Attachment(
            $kind,
            self::string($file['file_id']),
            mime: $mime,
            size: is_int($file['file_size'] ?? null) ? max(0, $file['file_size']) : 0,
            filename: self::string($file['file_name'] ?? '')
        );
    }
}
