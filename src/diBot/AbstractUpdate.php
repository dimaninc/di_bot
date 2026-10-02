<?php
namespace diBot;

abstract class AbstractUpdate
{
    public const MEMBERSHIP_STOPPED = 'stopped';
    public const MEMBERSHIP_STARTED = 'started';

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
    /**
     * Виды содержимого, которое библиотека не отдаёт в attachments: voice, audio, video,
     * video_note, sticker, animation, location, contact, poll, other. Только виды, без
     * ссылок на файлы; порядок появления, без повторов.
     * @var list<string>
     */
    public array $otherContent = [];
    /**
     * Собеседник остановил (заблокировал) или вернул бота – только в личном чате:
     * MEMBERSHIP_STOPPED/STARTED; '' – обычный апдейт. Групповые события (бота добавили или
     * удалили) не отдаются: у MAX их в подписке нет.
     */
    public string $membership = '';

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

    protected function addOtherContent(string $kind): void
    {
        if (!in_array($kind, $this->otherContent, true)) {
            $this->otherContent[] = $kind;
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
