<?php
namespace diBot\Instagram;

/** Профиль собеседника по IGSID (User Profile API). */
readonly final class UserProfile
{
    public function __construct(
        public string $id,
        public string $username = '',
        public string $name = '',
        // null – площадка поле не вернула: «неизвестно», а не «не подписан».
        public ?bool $followsBusiness = null,
        public ?bool $businessFollowsUser = null,
        public bool $isVerified = false,
        public ?int $followerCount = null
    ) {
    }

    public static function fromArray(array $data): ?self
    {
        $string = static fn(mixed $v): string => is_string($v) || is_int($v) ? (string) $v : '';
        $bool = static fn(mixed $v): ?bool => is_bool($v) ? $v : null;
        $id = $string($data['id'] ?? '');
        if ($id === '') {
            return null;
        }
        return new self(
            $id,
            $string($data['username'] ?? ''),
            $string($data['name'] ?? ''),
            $bool($data['is_user_follow_business'] ?? null),
            $bool($data['is_business_follow_user'] ?? null),
            ($data['is_verified_user'] ?? false) === true,
            is_int($data['follower_count'] ?? null) ? max(0, $data['follower_count']) : null
        );
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }
}
