<?php
namespace diBot;

use diBot\Exception\ApiException;
use diBot\Http\Client;
use diBot\Http\CurlClient;
use diBot\Http\Request;
use diBot\Http\Response;
use diBot\Outgoing\Media;
use diBot\Outgoing\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractBotApi
{
    // Консервативный лимит библиотеки: оставляем место multipart в 8-МБ Worker.
    public const FILE_MAX_BYTES = 7 * 1024 * 1024;
    protected Client $http;
    protected LoggerInterface $logger;

    public function __construct(
        public readonly Config $config,
        ?Client $http = null,
        ?LoggerInterface $logger = null
    ) {
        $this->http = $http ?? new CurlClient();
        $this->logger = $logger ?? new NullLogger();
    }

    abstract public function platform(): Platform;
    abstract public function sendMessage(
        string $chatId,
        string $text,
        ?Keyboard $keyboard = null
    ): array;
    abstract public function sendMedia(
        string $chatId,
        Media $media,
        Transport $transport,
        ?Keyboard $keyboard = null
    ): array;
    abstract public function sendFileId(
        string $chatId,
        string $kind,
        string $fileId,
        string $caption = '',
        ?Keyboard $keyboard = null
    ): array;
    /** null/пустая клавиатура удаляет кнопки; isCaption сохраняет медиа. */
    abstract public function editMessage(
        string $chatId,
        string $messageId,
        string $text,
        ?Keyboard $keyboard = null,
        bool $isCaption = false
    ): array;
    abstract public function answerCallback(string $callbackId, string $notification = ''): array;
    abstract public function getMe(): array;
    abstract public function rateLimitDelay(array $response, int $httpStatus = 0): int;
    abstract public function downloadAttachment(
        string $ref,
        string $url = ''
    ): \diBot\Attachment\Download;
    /** @param list<array{command:string,description:string}> $commands */
    abstract public function setCommands(array $commands): array;
    abstract public function setWebhook(string $url): array;
    abstract public function deleteWebhook(): void;
    abstract public function getUpdates(?string $cursor = null, int $timeout = 25): PollBatch;

    public function log(string $event, array $context = []): void
    {
        try {
            $this->logger->warning($event, ['platform' => $this->platform()->name] + $context);
        } catch (\Throwable) {
            // Ошибка логгера не должна срывать запасную отправку или ACK вебхука.
        }
    }

    protected function exchange(Request $request): Response
    {
        if ($this->config->token === '') {
            throw new ApiException(0, 'disabled');
        }
        try {
            return $this->http->send($request);
        } catch (\Throwable) {
            // Не передаём исходное исключение: URL cURL может содержать токен.
            $this->log('Bot transport failed');
            throw new ApiException(0, 'network_error');
        }
    }

    /** Единый формат меню с консервативными лимитами библиотеки. */
    protected function commandList(array $commands, string $nameKey): array
    {
        if (!array_is_list($commands) || count($commands) > 32) {
            throw new \InvalidArgumentException('Expected at most 32 commands');
        }
        $result = [];
        foreach ($commands as $command) {
            if (
                !is_array($command) ||
                !is_string($command['command'] ?? null) ||
                !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $command['command']) ||
                !is_string($command['description'] ?? null) ||
                !mb_check_encoding($command['description'], 'UTF-8') ||
                Text::length($command['description']) < 1 ||
                Text::length($command['description']) > 128
            ) {
                throw new \InvalidArgumentException('Invalid bot command');
            }
            $result[] = [$nameKey => $command['command'], 'description' => $command['description']];
        }
        return $result;
    }

    protected function assertText(string $text, bool $caption = false): void
    {
        if (
            !mb_check_encoding($text, 'UTF-8') ||
            Text::length($text) > ($caption ? static::CAPTION_MAX_UNITS : static::TEXT_MAX_UNITS)
        ) {
            throw new \InvalidArgumentException('Text does not fit platform limit');
        }
    }

    protected function mediaBytes(Media $media, Transport $transport): string
    {
        $this->assertText($media->caption, true);
        if (
            $transport !== Transport::Upload ||
            $media->size > static::FILE_MAX_BYTES ||
            $media->localPath === '' ||
            str_contains($media->localPath, '://') ||
            !is_file($media->localPath)
        ) {
            throw new \InvalidArgumentException('Media cannot be uploaded');
        }
        $bytes = @file_get_contents($media->localPath, false, null, 0, static::FILE_MAX_BYTES + 1);
        if (
            $bytes === false ||
            strlen($bytes) !== $media->size ||
            strlen($bytes) > static::FILE_MAX_BYTES
        ) {
            throw new \RuntimeException('Media changed or exceeds the upload limit');
        }
        return $bytes;
    }

    final public function validateCursor(?string $cursor): void
    {
        if ($cursor !== null && !preg_match('/^(0|[1-9][0-9]{0,18})$/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid polling cursor');
        }
    }
}
