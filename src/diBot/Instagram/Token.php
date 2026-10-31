<?php
namespace diBot\Instagram;

/** Токен системного пользователя бизнес-интеграции (BISU): хранить зашифрованным, в журнал не писать. */
readonly final class Token
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        // Секунды жизни; 0 – бессрочный (так Meta выдаёт токен BISU).
        public int $expiresIn = 0
    ) {
    }

    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'expiresIn' => $this->expiresIn];
    }
}
