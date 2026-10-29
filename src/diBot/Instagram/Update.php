<?php
namespace diBot\Instagram;

use diBot\AbstractUpdate;
use diBot\Platform;

/**
 * Одно событие из Webhook::events(). Личка – сообщение, quick reply, postback; комментарий
 * – change с полем comments/live_comments. Эхо своих сообщений, прочтения, реакции,
 * правки, удаления и прочие события – null. Команд нет: /start в Instagram не бывает.
 */
final class Update extends AbstractUpdate
{
    /** Instagram-аккаунт бизнеса, получивший событие (entry.id): по нему выбирают бота. */
    public string $accountId = '';
    public ?Comment $comment = null;

    // image у Meta – также GIF и стикер. Пересланные публикации, reels, истории – other.
    private const OTHER_CONTENT = ['audio' => 'audio', 'video' => 'video'];

    public static function fromArray(array $data): ?self
    {
        $u = new self();
        $u->platform = Platform::Instagram;
        $u->raw = $data;
        $u->accountId = self::string($data['entry_id'] ?? '');
        if ($u->accountId === '') {
            return null;
        }
        $u->isPrivateChat = true;
        $u->profile([]);
        if (is_array($data['messaging'] ?? null)) {
            $u = self::messaging($u, $data['messaging']);
        } elseif (is_array($data['change'] ?? null)) {
            $u = self::change($u, $data['change'], self::string($data['time'] ?? ''));
        } else {
            return null;
        }
        // Своё же действие бизнеса (ответ аккаунта под постом, эхо) – не входящее: иначе бот
        // ответил бы сам себе.
        if ($u === null || $u->userId === '' || $u->userId === $u->accountId) {
            return null;
        }
        $u->chatId = $u->userId;
        return $u;
    }

    private static function messaging(self $u, array $event): ?self
    {
        $sender = is_array($event['sender'] ?? null) ? $event['sender'] : [];
        $u->userId = self::string($sender['id'] ?? '');
        $postback = $event['postback'] ?? null;
        if (is_array($postback)) {
            // У postback свой mid – стабильный идентификатор нажатия для дедупликации.
            $u->callbackId = self::string($postback['mid'] ?? '');
            $u->payload = self::string($postback['payload'] ?? '');
            // Подпись кнопки, как text у quick reply: вид нажатия не зависит от числа кнопок.
            $u->text = self::string($postback['title'] ?? '');
            $u->updateId = 'postback:' . $u->callbackId;
            return $u->callbackId !== '' ? $u : null;
        }
        $message = $event['message'] ?? null;
        if (
            !is_array($message) ||
            ($message['is_echo'] ?? false) === true ||
            ($message['is_deleted'] ?? false) === true
        ) {
            return null;
        }
        $u->messageId = self::string($message['mid'] ?? '');
        if ($u->messageId === '') {
            return null;
        }
        $u->updateId = 'message:' . $u->messageId;
        $u->text = self::string($message['text'] ?? '');
        $quick = is_array($message['quick_reply'] ?? null) ? $message['quick_reply'] : [];
        $u->payload = self::string($quick['payload'] ?? '');
        // Нажатие quick reply – тот же callback, что postback: иначе 4-я кнопка меняла бы тип апдейта.
        if ($u->payload !== '') {
            $u->callbackId = $u->messageId;
        }
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        foreach ($attachments as $item) {
            $type = is_array($item) ? $item['type'] ?? null : null;
            // Битый элемент – не содержимое собеседника.
            if (!is_string($type)) {
                continue;
            }
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $url = self::string($payload['url'] ?? '');
            if (($type === 'image' || $type === 'file') && $url !== '') {
                // Вид – как отправлено; ref у Instagram нет, скачивание только по ссылке CDN.
                $u->attachments[] = new \diBot\Attachment\Attachment(
                    $type === 'image' ? 'photo' : 'document',
                    '',
                    $url
                );
            } else {
                $u->addOtherContent(self::OTHER_CONTENT[$type] ?? 'other');
            }
        }
        if (($message['is_unsupported'] ?? false) === true) {
            $u->addOtherContent('other');
        }
        return $u;
    }

    private static function change(self $u, array $change, string $time): ?self
    {
        $field = $change['field'] ?? null;
        $value = $change['value'] ?? null;
        if (($field !== 'comments' && $field !== 'live_comments') || !is_array($value)) {
            return null;
        }
        $comment = Comment::fromArray($value, $field === 'live_comments');
        if ($comment === null || $comment->fromId === '') {
            return null;
        }
        if ($comment->timestamp === '' && $time !== '') {
            $comment = new Comment(
                $comment->id,
                $comment->mediaId,
                $comment->parentId,
                $comment->text,
                $comment->fromId,
                $comment->fromUsername,
                $time,
                $comment->live
            );
        }
        $u->comment = $comment;
        // Комментарий публичный: общий код по isPrivateChat не примет его за личку.
        $u->isPrivateChat = false;
        $u->updateId = 'comment:' . $comment->id;
        $u->userId = $comment->fromId;
        $u->text = $comment->text;
        $u->userProfile['username'] = $comment->fromUsername;
        return $u;
    }
}
