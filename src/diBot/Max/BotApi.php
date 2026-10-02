<?php
namespace diBot\Max;

use diBot\{AbstractBotApi, Config, Keyboard, Platform, PollBatch};
use diBot\Exception\{ApiException, MediaNotReadyException};
use diBot\Http\{Multipart, Request};
use diBot\Outgoing\{Media, Transport};

class BotApi extends AbstractBotApi
{
    public const DEFAULT_BASE_URL = 'https://platform-api2.max.ru';
    public const TEXT_MAX_UNITS = 4000;
    public const CAPTION_MAX_UNITS = 4000;
    public const BUTTON_TEXT_MAX_UNITS = 64;
    public const CALLBACK_MAX_BYTES = 1024;
    public const BUTTONS_PER_ROW = 7;
    public const BUTTONS_MAX = 210;
    public const ROWS_MAX = 30;
    // Один список на вебхук и поллинг, чтобы подписки не разошлись.
    public const UPDATE_TYPES = [
        'message_created',
        'message_callback',
        'bot_started',
        'bot_stopped',
    ];

    public function platform(): Platform
    {
        return Platform::Max;
    }
    public function getMe(): array
    {
        return $this->request('GET', '/me');
    }
    public function setCommands(array $commands): array
    {
        return $this->request('PATCH', '/me/commands', [
            'commands' => $this->commandList($commands, 'name'),
        ]);
    }

    public static function defaultCaBundle(): string
    {
        return dirname(__DIR__, 3) . '/resources/max-api-ca-bundle.pem';
    }

    public function rateLimitDelay(array $response, int $httpStatus = 0): int
    {
        $code = is_string($response['code'] ?? null) ? strtolower($response['code']) : '';
        return $httpStatus === 429 || str_contains($code, 'too.many.requests') ? 1 : 0;
    }

    public function downloadAttachment(string $ref, string $url = ''): \diBot\Attachment\Download
    {
        if ($url === '') {
            throw new \diBot\Exception\DownloadException(0, 'missing_url', true);
        }
        return (new \diBot\Attachment\Downloader($this->http, $this->config))->download($url);
    }

    public function sendMessage(string $chatId, string $text, ?Keyboard $keyboard = null): array
    {
        $this->assertText($text);
        return $this->request('POST', '/messages', $this->body($text, $keyboard), [
            'chat_id' => $chatId,
        ]);
    }

    public function editMessage(
        string $chatId,
        string $messageId,
        string $text,
        ?Keyboard $keyboard = null,
        bool $isCaption = false
    ): array {
        $this->assertText($text, $isCaption);
        $attachments = [];
        $message = $this->request('GET', '/messages/' . rawurlencode($messageId));
        $messageBody = $message['body'] ?? null;
        if (!is_array($messageBody)) {
            throw new ApiException(200, 'invalid_message');
        }
        $existing = array_key_exists('attachments', $messageBody)
            ? $messageBody['attachments']
            : [];
        if (!is_array($existing) || !array_is_list($existing)) {
            throw new ApiException(200, 'invalid_attachments');
        }
        foreach ($existing as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['type'] ?? null)) {
                throw new ApiException(200, 'invalid_attachments');
            }
            if ($attachment['type'] === 'inline_keyboard') {
                continue;
            }
            if (!$isCaption) {
                // attachments: [] удалит медиа; ошибка режима должна остановить PUT.
                throw new ApiException(200, 'caption_required');
            }
            // GET и PUT имеют разные схемы: переносим только токен фото/документа.
            if (!in_array($attachment['type'], ['image', 'file'], true)) {
                throw new ApiException(200, 'unsupported_attachment');
            }
            $payload = $attachment['payload'] ?? null;
            if (
                !is_array($payload) ||
                !is_string($payload['token'] ?? null) ||
                $payload['token'] === ''
            ) {
                throw new ApiException(200, 'invalid_attachments');
            }
            $attachments[] = [
                'type' => $attachment['type'],
                'payload' => ['token' => $payload['token']],
            ];
        }
        return $this->request('PUT', '/messages', $this->body($text, $keyboard, $attachments), [
            'message_id' => $messageId,
        ]);
    }

    public function sendMedia(
        string $chatId,
        Media $media,
        Transport $transport,
        ?Keyboard $keyboard = null
    ): array {
        $this->assertText($media->caption, true);
        if ($media->size > self::FILE_MAX_BYTES) {
            throw new \InvalidArgumentException('File too large');
        }
        $type = $media->kind === 'photo' ? 'image' : 'file';
        if ($transport === Transport::Url) {
            if ($type !== 'image' || $media->size > 5 * 1024 * 1024 || $media->publicUrl === '') {
                throw new \InvalidArgumentException('Invalid URL transport');
            }
            $payload = ['url' => $media->publicUrl];
        } else {
            $bytes = $this->mediaBytes($media, $transport);
            $endpoint = $this->request('POST', '/uploads', query: ['type' => $type]);
            if (!is_string($endpoint['url'] ?? null)) {
                throw new ApiException(200, 'missing_upload_url');
            }
            // Схему/порт проверяем до вызова клиента; DNS проверяется и фиксируется CurlClient.
            Config::assertHttpsUrl($endpoint['url']);
            if ((parse_url($endpoint['url'], PHP_URL_PORT) ?? 443) !== 443) {
                throw new ApiException(0, 'invalid_upload_url');
            }
            [$body, $contentType] = Multipart::build(
                [],
                'data',
                $media->filename ?: basename($media->localPath),
                $media->mime,
                $bytes
            );
            $uploaded = $this->exchange(
                new Request(
                    'POST',
                    $endpoint['url'],
                    [
                        'Content-Type: ' . $contentType,
                        'Content-Length: ' . strlen($body),
                        'Expect:',
                    ],
                    $body,
                    $this->config->uploadTimeout,
                    $this->config->connectTimeout,
                    null,
                    true
                )
            );
            if ($uploaded->status === 429) {
                throw new ApiException(
                    429,
                    'rate_limit',
                    retryAfter: ApiException::retrySeconds(
                        $uploaded->headers['retry-after'] ?? null
                    )
                );
            }
            // Хранилище вправе вернуть пустое 2xx; успех определяется HTTP-кодом.
            if ($uploaded->status < 200 || $uploaded->status >= 300) {
                throw new ApiException($uploaded->status, 'upload_failed');
            }
            $token = is_string($endpoint['token'] ?? null) ? $endpoint['token'] : '';
            if ($uploaded->body !== '') {
                try {
                    $result = $uploaded->json();
                } catch (ApiException) {
                    $result = [];
                }
                if (is_string($result['token'] ?? null) && $result['token'] !== '') {
                    $token = $result['token'];
                }
                foreach (is_array($result['photos'] ?? null) ? $result['photos'] : [] as $photo) {
                    if (
                        is_array($photo) &&
                        is_string($photo['token'] ?? null) &&
                        $photo['token'] !== ''
                    ) {
                        $token = $photo['token'];
                        break;
                    }
                }
            }
            if ($token === '') {
                throw new ApiException(200, 'missing_upload_token');
            }
            $payload = ['token' => $token];
        }
        return $this->postMedia(
            $chatId,
            $this->body($media->caption, $keyboard, [['type' => $type, 'payload' => $payload]]),
            $media->kind,
            $payload['token'] ?? null
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
        return $this->postMedia(
            $chatId,
            $this->body($caption, $keyboard, [
                ['type' => $kind === 'photo' ? 'image' : 'file', 'payload' => ['token' => $fileId]],
            ]),
            $kind,
            $fileId
        );
    }

    protected function canRetryMedia(): bool
    {
        return PHP_SAPI === 'cli' && $this->config->retryMediaInCli;
    }
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function postMedia(
        string $chatId,
        #[\SensitiveParameter] array $body,
        string $kind,
        #[\SensitiveParameter] ?string $fileId
    ): array {
        $delays = $this->canRetryMedia() ? [2, 4, 8] : [];
        while (true) {
            try {
                return $this->request('POST', '/messages', $body, ['chat_id' => $chatId]);
            } catch (ApiException $e) {
                if ($e->reason !== 'attachment.not.ready') {
                    throw $e;
                }
                if (!$delays) {
                    if ($fileId !== null) {
                        throw new MediaNotReadyException($e->httpStatus, $kind, $fileId);
                    }
                    throw $e;
                }
                $this->pause(array_shift($delays));
            }
        }
    }

    public function answerCallback(string $callbackId, string $notification = ''): array
    {
        // MAX требует notification или message; тихого подтверждения у API нет.
        if ($notification === '') {
            return ['ok' => true];
        }
        return $this->request(
            'POST',
            '/answers',
            ['notification' => \diBot\Text::truncate($notification, 200)],
            ['callback_id' => $callbackId]
        );
    }

    public function setWebhook(string $url): array
    {
        Config::assertHttpsUrl($url);
        if (!preg_match('/^[a-zA-Z0-9_-]{5,256}$/D', $this->config->webhookSecret)) {
            throw new \InvalidArgumentException('MAX webhook secret is missing or invalid');
        }
        return $this->request('POST', '/subscriptions', [
            'url' => $url,
            'secret' => $this->config->webhookSecret,
            'update_types' => self::UPDATE_TYPES,
        ]);
    }

    public function deleteWebhook(?string $url = null): void
    {
        if ($url !== null) {
            // Ровно один запрос и только своя подписка: подписки других сервисов не трогаем.
            Config::assertHttpsUrl($url);
            $this->request('DELETE', '/subscriptions', query: ['url' => $url]);
            return;
        }
        $result = $this->request('GET', '/subscriptions');
        if (!is_array($result['subscriptions'] ?? null)) {
            throw new ApiException(200, 'invalid_subscriptions');
        }
        foreach ($result['subscriptions'] as $sub) {
            if (!is_array($sub) || !is_string($sub['url'] ?? null)) {
                throw new ApiException(200, 'invalid_subscription');
            }
            $this->request('DELETE', '/subscriptions', query: ['url' => $sub['url']]);
        }
    }

    public function getUpdates(?string $cursor = null, int $timeout = 25): PollBatch
    {
        $this->validateCursor($cursor);
        if ($timeout < 0 || $timeout > 90) {
            throw new \InvalidArgumentException('Invalid polling timeout');
        }
        $query = [
            'limit' => 100,
            'timeout' => $timeout,
            'types' => implode(',', self::UPDATE_TYPES),
        ];
        if ($cursor !== null) {
            $query['marker'] = $cursor;
        }
        $result = $this->request(
            'GET',
            '/updates',
            query: $query,
            timeout: $timeout + $this->config->requestTimeout
        );
        if (!is_array($result['updates'] ?? null)) {
            throw new ApiException(200, 'invalid_updates');
        }
        $marker = $result['marker'] ?? null;
        if ($marker !== null && !is_int($marker) && !is_string($marker)) {
            throw new ApiException(200, 'invalid_marker');
        }
        if ($marker === null && $result['updates'] !== []) {
            throw new ApiException(200, 'missing_marker');
        }
        $next = $marker === null ? $cursor : (string) $marker;
        $this->validateCursor($next);
        $updates = [];
        foreach ($result['updates'] as $raw) {
            if (!is_array($raw)) {
                $this->log('Invalid MAX update skipped');
                continue;
            }
            $update = Update::fromArray($raw);
            if ($update !== null) {
                $updates[] = $update;
            }
        }
        return new PollBatch($updates, $next);
    }

    private function body(string $text, ?Keyboard $keyboard, array $attachments = []): array
    {
        if ($keyboard !== null) {
            $rendered = $keyboard->render($this->platform());
            if ($rendered['payload']['buttons'] !== []) {
                $attachments[] = $rendered;
            }
        }
        return ['text' => $text, 'attachments' => $attachments];
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?int $timeout = null
    ): array {
        $base = rtrim($this->config->baseUrl ?: self::DEFAULT_BASE_URL, '/');
        if (parse_url($base, PHP_URL_QUERY) !== null) {
            throw new \InvalidArgumentException('API base URL cannot contain query');
        }
        $ca =
            parse_url($base, PHP_URL_HOST) === 'platform-api2.max.ru'
                ? $this->config->caBundle ?? self::defaultCaBundle()
                : null;
        $url =
            $base .
            $path .
            ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $response = $this->exchange(
            new Request(
                $method,
                $url,
                ['Authorization: ' . $this->config->token, 'Content-Type: application/json'],
                $body === null
                    ? null
                    : json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $timeout ?? $this->config->requestTimeout,
                $this->config->connectTimeout,
                $ca
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
        if (
            $response->status < 200 ||
            $response->status >= 300 ||
            isset($data['code']) ||
            ($data['success'] ?? true) === false
        ) {
            $reason =
                ($data['code'] ?? '') === 'attachment.not.ready'
                    ? 'attachment.not.ready'
                    : 'api_error';
            $blocked = in_array(
                $data['code'] ?? '',
                ['bot.blocked', 'user.blocked', 'chat.blocked', 'chat.denied'],
                true
            );
            $this->log('MAX API rejected request', [
                'status' => $response->status,
                'shape' => \diBot\BodyShape::describe($body),
            ]);
            throw new ApiException($response->status, $reason, $blocked);
        }
        return $data;
    }
}
