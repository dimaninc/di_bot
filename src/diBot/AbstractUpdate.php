<?php
namespace diBot;

abstract class AbstractUpdate
{
    public Platform $platform;
    public string $updateId = '';
    public string $userId = '';
    public string $chatId = '';
    public string $messageId = '';
    public bool $isPrivateChat = false;
    public string $text = '';
    public string $command = '';
    public string $commandPayload = '';
    public string $commandTarget = '';
    public string $callbackId = '';
    public string $payload = '';
    public array $userProfile = [];
    /** @var list<\diBot\Attachment\Attachment> */
    public array $attachments = [];

    public function isCallback(): bool
    {
        return $this->callbackId !== '';
    }

    protected function parseCommand(): void
    {
        if (
            preg_match(
                '~^/([a-zA-Z0-9_]{1,32})(?:@([a-zA-Z0-9_]+))?(?:\s+(.*))?$~suD',
                trim($this->text),
                $m
            )
        ) {
            $this->command = strtolower($m[1]);
            $this->commandTarget = $m[2] ?? '';
            $this->commandPayload = trim($m[3] ?? '');
        }
    }

    protected static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    protected function profile(array $user): void
    {
        foreach (['username', 'first_name', 'last_name'] as $key) {
            $this->userProfile[$key] = self::string($user[$key] ?? '');
        }
        $this->userProfile['language'] = self::string(
            $user['language_code'] ?? ($user['language'] ?? '')
        );
        if ($this->userProfile['first_name'] === '') {
            $this->userProfile['first_name'] = self::string($user['name'] ?? '');
        }
    }
}
