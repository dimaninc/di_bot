<?php
namespace diBot\Instagram;

/** Комментарий под публикацией: из вебхука comments/live_comments или из getComments(). */
readonly final class Comment
{
    public function __construct(
        public string $id,
        public string $mediaId = '',
        // '' – комментарий верхнего уровня, иначе id комментария, на который это ответ.
        public string $parentId = '',
        public string $text = '',
        public string $fromId = '',
        public string $fromUsername = '',
        // Как прислала площадка: ISO 8601 у API, Unix-время у вебхука (тогда из entry.time).
        public string $timestamp = '',
        // Комментарий к прямому эфиру: private reply возможен только пока эфир идёт.
        public bool $live = false
    ) {
    }

    public static function fromArray(array $data, bool $live = false): ?self
    {
        $string = static fn(mixed $v): string => is_string($v) || is_int($v) ? (string) $v : '';
        $from = is_array($data['from'] ?? null) ? $data['from'] : [];
        $media = is_array($data['media'] ?? null) ? $data['media'] : [];
        // Вариант Instagram Login присылает id, Facebook Login – comment_id.
        $id = $string($data['id'] ?? ($data['comment_id'] ?? ''));
        if ($id === '') {
            return null;
        }
        return new self(
            $id,
            $string($media['id'] ?? ''),
            $string($data['parent_id'] ?? ''),
            $string($data['text'] ?? ''),
            $string($from['id'] ?? ''),
            $string($from['username'] ?? ($data['username'] ?? '')),
            $string($data['timestamp'] ?? ''),
            $live
        );
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'mediaId' => $this->mediaId, 'live' => $this->live];
    }
}
