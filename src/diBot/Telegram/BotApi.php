<?php
namespace diBot\Telegram;

use diBot\{AbstractBotApi, Config, Keyboard, Platform, PollBatch};
use diBot\Exception\ApiException;
use diBot\Http\{Multipart, Request};
use diBot\Outgoing\{Media, Transport};

class BotApi extends AbstractBotApi
{
    public const DEFAULT_BASE_URL = 'https://api.telegram.org';
    public const TEXT_MAX_UNITS = 4096;
    public const CAPTION_MAX_UNITS = 1024;
    // Подпись 64 UTF-16 – выбранный UX-лимит библиотеки, не ограничение Bot API.
    public const BUTTON_TEXT_MAX_UNITS = 64;
    public const CALLBACK_MAX_BYTES = 64;
    public const BUTTONS_PER_ROW = 8;
    public const BUTTONS_MAX = 100;
    public const ROWS_MAX = 100;

    public function platform(): Platform
    {
        return Platform::Telegram;
    }
    public function getMe(): array
    {
        return $this->request('getMe');
    }
    public function setCommands(array $commands): array
    {
        return $this->request('setMyCommands', [
            'commands' => $this->commandList($commands, 'command'),
        ]);
    }

    public function rateLimitDelay(array $response, int $httpStatus = 0): int
    {
        if (($response['error_code'] ?? 0) !== 429 && $httpStatus !== 429) {
            return 0;
        }
        return ApiException::retrySeconds($response['parameters']['retry_after'] ?? null);
    }

    public function downloadAttachment(string $ref, string $url = ''): \diBot\Attachment\Download
    {
        if ($ref === '') {
            throw new \diBot\Exception\DownloadException(0, 'missing_ref', true);
        }
        try {
            $meta = $this->request('getFile', ['file_id' => $ref]);
        } catch (ApiException $e) {
            throw new \diBot\Exception\DownloadException(
                $e->httpStatus,
                $e->reason,
                in_array($e->httpStatus, [400, 404], true) ||
                    in_array(
                        $e->reason,
                        [
                            'invalid_token',
                            'disabled',
                            'tls_untrusted_ca',
                            'tls_ca_file',
                            'tls_client_certificate',
                            'response_too_large',
                        ],
                        true
                    ),
                $e->retryAfter
            );
        } catch (\InvalidArgumentException) {
            throw new \diBot\Exception\DownloadException(0, 'invalid_configuration', true);
        }
        $path = $meta['result']['file_path'] ?? null;
        // Кодируем сегменты отдельно: file_path не может сменить хост или выйти из /file/.
        $segments = is_string($path) ? explode('/', $path) : [];
        if (
            !$segments ||
            strlen($path) > 2048 ||
            array_intersect($segments, ['', '.', '..']) ||
            preg_match('/[\\x00-\\x20\\x7f\\\\]/', $path)
        ) {
            throw new \diBot\Exception\DownloadException(200, 'invalid_file_path', true);
        }
        $path = implode('/', array_map('rawurlencode', $segments));
        if (
            is_int($meta['result']['file_size'] ?? null) &&
            $meta['result']['file_size'] > $this->config->maxDownloadBytes
        ) {
            throw new \diBot\Exception\DownloadException(200, 'too_big', true);
        }
        $base = rtrim($this->config->baseUrl ?: self::DEFAULT_BASE_URL, '/');
        $headers =
            $base === self::DEFAULT_BASE_URL ? [] : ['X-Proxy-Auth: ' . $this->config->proxySecret];
        return (new \diBot\Attachment\Downloader($this->http, $this->config))->download(
            $base . '/file/bot' . $this->config->token . '/' . $path,
            $headers,
            false
        );
    }

    public function sendMessage(string $chatId, string $text, ?Keyboard $keyboard = null): array
    {
        $this->assertText($text);
        return $this->request(
            'sendMessage',
            $this->withKeyboard(['chat_id' => $chatId, 'text' => $text], $keyboard)
        );
    }

    public function editMessage(
        string $chatId,
        string $messageId,
        string $text,
        ?Keyboard $keyboard = null,
        bool $isCaption = false
    ): array {
        $this->assertText($text, $isCaption);
        $body = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            $isCaption ? 'caption' : 'text' => $text,
        ];
        return $this->request(
            $isCaption ? 'editMessageCaption' : 'editMessageText',
            $this->withKeyboard($body, $keyboard ?? new Keyboard())
        );
    }

    public function sendMedia(
        string $chatId,
        Media $media,
        Transport $transport,
        ?Keyboard $keyboard = null
    ): array {
        $bytes = $this->mediaBytes($media, $transport);
        $body = $this->withKeyboard(
            ['chat_id' => $chatId, 'caption' => $media->caption],
            $keyboard
        );
        [$multipart, $type] = Multipart::build(
            $body,
            $media->kind,
            $media->filename ?: basename($media->localPath),
            $media->mime,
            $bytes
        );
        return $this->request(
            $media->kind === 'photo' ? 'sendPhoto' : 'sendDocument',
            $body + [$media->kind => $bytes],
            $multipart,
            $type
        );
    }

    public function sendFileId(
        string $chatId,
        string $kind,
        string $fileId,
        string $caption = '',
        ?Keyboard $keyboard = null
    ): array {
        if (!in_array($kind, ['photo', 'document'], true) || $fileId === '') {
            throw new \InvalidArgumentException('Invalid file reference');
        }
        $this->assertText($caption, true);
        return $this->request(
            $kind === 'photo' ? 'sendPhoto' : 'sendDocument',
            $this->withKeyboard(
                ['chat_id' => $chatId, $kind => $fileId, 'caption' => $caption],
                $keyboard
            )
        );
    }

    public function answerCallback(string $callbackId, string $notification = ''): array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => \diBot\Text::truncate($notification, 200),
        ]);
    }

    public function setWebhook(string $url): array
    {
        Config::assertHttpsUrl($url);
        if (
            $this->config->webhookSecret === '' ||
            !preg_match('/^[a-zA-Z0-9_-]{1,256}$/D', $this->config->webhookSecret)
        ) {
            throw new \InvalidArgumentException('Telegram webhook secret is missing or invalid');
        }
        return $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $this->config->webhookSecret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->request('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getUpdates(?string $cursor = null, int $timeout = 25): PollBatch
    {
        $this->validateCursor($cursor);
        if ($timeout < 0 || $timeout > 50) {
            throw new \InvalidArgumentException('Invalid polling timeout');
        }
        $result = $this->request(
            'getUpdates',
            [
                'offset' => $cursor ?? '0',
                'timeout' => $timeout,
                'allowed_updates' => ['message', 'callback_query'],
            ],
            timeout: $timeout + $this->config->requestTimeout
        );
        if (!is_array($result['result'] ?? null)) {
            throw new ApiException(200, 'invalid_updates');
        }
        $updates = [];
        $next = $cursor;
        $invalidId = false;
        foreach ($result['result'] as $raw) {
            if (
                !is_array($raw) ||
                (!is_int($raw['update_id'] ?? null) && !is_string($raw['update_id'] ?? null)) ||
                !ctype_digit((string) $raw['update_id']) ||
                strlen((string) $raw['update_id']) > 15
            ) {
                $invalidId = true;
                $this->log('Invalid Telegram update ID skipped');
                continue;
            }
            $candidate = (string) ((int) $raw['update_id'] + 1);
            if ($next === null || (int) $candidate > (int) $next) {
                $next = $candidate;
            }
            $update = Update::fromArray($raw);
            if ($update !== null) {
                $updates[] = $update;
            }
        }
        // Без пригодного ID нельзя вычислить offset, не рискуя потерять чужие события.
        if ($invalidId && $next === $cursor) {
            throw new ApiException(200, 'invalid_update_id');
        }
        return new PollBatch($updates, $next);
    }

    private function withKeyboard(array $body, ?Keyboard $keyboard): array
    {
        if ($keyboard !== null) {
            $body['reply_markup'] = $keyboard->render($this->platform());
        }
        return $body;
    }

    private function request(
        string $method,
        array $body = [],
        ?string $multipart = null,
        string $contentType = 'application/json',
        ?int $timeout = null
    ): array {
        if (!preg_match('/^[A-Za-z0-9:_-]+$/D', $this->config->token)) {
            throw new ApiException(0, 'invalid_token');
        }
        $base = rtrim($this->config->baseUrl ?: self::DEFAULT_BASE_URL, '/');
        if (parse_url($base, PHP_URL_QUERY) !== null) {
            throw new \InvalidArgumentException('API base URL cannot contain query');
        }
        $headers = ['Content-Type: ' . $contentType];
        if ($base !== self::DEFAULT_BASE_URL) {
            if ($this->config->proxySecret === '') {
                throw new \InvalidArgumentException('Telegram proxy requires a secret');
            }
            $headers[] = 'X-Proxy-Auth: ' . $this->config->proxySecret;
        }
        $encoded =
            $multipart ?? json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Length: ' . strlen($encoded);
        $headers[] = 'Expect:';
        $response = $this->exchange(
            new Request(
                'POST',
                $base . '/bot' . $this->config->token . '/' . $method,
                $headers,
                $encoded,
                $timeout ??
                    ($multipart !== null
                        ? $this->config->uploadTimeout
                        : $this->config->requestTimeout),
                $this->config->connectTimeout
            )
        );
        try {
            $data = $response->json();
        } catch (ApiException $e) {
            if ($response->status !== 429) {
                throw $e;
            }
            $data = [];
        }
        $delay = $this->rateLimitDelay($data, $response->status);
        if ($delay > 0) {
            $delay = max(
                $delay,
                ApiException::retrySeconds($response->headers['retry-after'] ?? null)
            );
            throw new ApiException($response->status, 'rate_limit', retryAfter: $delay);
        }
        if ($response->status < 200 || $response->status >= 300 || ($data['ok'] ?? null) !== true) {
            $blocked = ($data['error_code'] ?? $response->status) === 403;
            $this->log('Telegram API rejected request', [
                'status' => $response->status,
                'shape' => \diBot\BodyShape::describe($body),
            ]);
            throw new ApiException($response->status, 'api_error', $blocked);
        }
        return $data;
    }
}
