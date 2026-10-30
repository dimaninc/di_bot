<?php
namespace diBot\Instagram;

/** Токен Business Login: хранить зашифрованным, в журнал не писать. */
readonly final class Token
{
    /** @param list<string> $permissions */
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        // user_id из обмена кода; у долгоживущего токена и обновления – ''.
        public string $userId = '',
        // Секунды жизни; 0 – площадка не сообщила (короткоживущий токен из обмена кода).
        public int $expiresIn = 0,
        public array $permissions = []
    ) {
    }

    public function __debugInfo(): array
    {
        return [
            'accessToken' => '[redacted]',
            'userId' => $this->userId,
            'expiresIn' => $this->expiresIn,
            'permissions' => $this->permissions,
        ];
    }
}
