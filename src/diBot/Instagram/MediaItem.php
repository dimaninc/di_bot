<?php
namespace diBot\Instagram;

/** Публикация аккаунта из getMedia(). */
readonly final class MediaItem
{
    public function __construct(
        public string $id,
        // Нет поля – ''.
        public string $caption = '',
        // IMAGE, VIDEO, CAROUSEL_ALBUM – как прислала площадка.
        public string $mediaType = '',
        public string $permalink = '',
        // Временные ссылки CDN: хранить и журналировать нельзя, только показывать.
        public string $mediaUrl = '',
        public string $thumbnailUrl = '',
        public string $timestamp = ''
    ) {
    }

    public static function fromArray(array $data): ?self
    {
        $string = static fn(mixed $v): string => is_string($v) || is_int($v) ? (string) $v : '';
        $id = $string($data['id'] ?? '');
        if ($id === '') {
            return null;
        }
        return new self(
            $id,
            $string($data['caption'] ?? ''),
            $string($data['media_type'] ?? ''),
            $string($data['permalink'] ?? ''),
            $string($data['media_url'] ?? ''),
            $string($data['thumbnail_url'] ?? ''),
            $string($data['timestamp'] ?? '')
        );
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'mediaType' => $this->mediaType];
    }
}
