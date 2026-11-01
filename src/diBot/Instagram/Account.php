<?php
namespace diBot\Instagram;

/** Аккаунт Instagram, доступ к которому клиент выдал при входе (Auth::accounts()). */
readonly final class Account
{
    public function __construct(
        // IG user id: Config::accountId и entry.id вебхука.
        public string $id,
        public string $username = ''
    ) {
    }

    public static function fromArray(array $data): ?self
    {
        $string = static fn(mixed $v): string => is_string($v) || is_int($v) ? (string) $v : '';
        $id = $string($data['id'] ?? '');
        if (!preg_match('/^[0-9]{1,32}$/D', $id)) {
            return null;
        }
        return new self($id, $string($data['username'] ?? ''));
    }
}
