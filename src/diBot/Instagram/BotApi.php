<?php
namespace diBot\Instagram;

use diBot\{AbstractBotApi, Config, Keyboard, Platform, PollBatch};
use diBot\Exception\ApiException;
use diBot\Http\Request;
use diBot\Outgoing\{Media, Transport};

/**
 * Instagram API with Instagram Login. Config::token – долгоживущий токен пользователя
 * Instagram (обновление – на стороне приложения, см. Auth). Вебхук – Webhook, не контроллер.
 */
class BotApi extends AbstractBotApi
{
    public const DEFAULT_BASE_URL = 'https://graph.instagram.com';
    public const GRAPH_VERSION = 'v26.0';
    // Текст меряется в байтах UTF-8 (≈500 символов кириллицы); единицы – для общих проверок.
    public const TEXT_MAX_BYTES = 1000;
    public const TEXT_MAX_UNITS = 1000;
    // Подписей к медиа нет: непустой текст Policy отклоняет как caption_too_long.
    public const CAPTION_MAX_UNITS = 0;
    public const TEMPLATE_TEXT_MAX_UNITS = 640;
    // Лимит комментария Instagram – 2200 символов; считаем в единицах UTF-16, как Text.
    public const COMMENT_MAX_CHARS = 2200;
    public const TEMPLATE_BUTTONS_MAX = 3;
    // Длиннее 20 символов Meta обрезает сама; режем заранее, по границе UTF-16.
    public const BUTTON_TEXT_MAX_UNITS = 20;
    // Лимит payload для Instagram не опубликован; 1000 – как у Messenger.
    public const CALLBACK_MAX_BYTES = 1000;
    public const BUTTONS_PER_ROW = 13;
    public const BUTTONS_MAX = 13;
    public const ROWS_MAX = 13;
    // Файл скачивает сама Meta по URL: лимит Worker не действует, действуют лимиты Send API.
    public const FILE_MAX_BYTES = 25 * 1024 * 1024;
    public const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
    public const PHOTO_MIMES = ['image/png', 'image/jpeg'];
    public const DOCUMENT_MIMES = ['application/pdf'];
    // Поля, на которые подписывается аккаунт. Набор полей адреса задаётся в панели Meta.
    public const UPDATE_TYPES = ['comments', 'live_comments', 'messages', 'messaging_postbacks'];

    public function platform(): Platform
    {
        return Platform::Instagram;
    }

    /**
     * ['account_id' => user_id – совпадает с entry.id вебхука, 'username',
     *  'app_scoped_id' => id – app-scoped ID, для маршрутизации не годится].
     */
    public function getMe(): array
    {
        $data = self::unwrap($this->request('GET', '/me', ['fields' => 'user_id,username,id']));
        $string = static fn(mixed $v): string => is_string($v) || is_int($v) ? (string) $v : '';
        $result = [
            'account_id' => $string($data['user_id'] ?? ''),
            'username' => $string($data['username'] ?? ''),
            'app_scoped_id' => $string($data['id'] ?? ''),
        ];
        if ($result['account_id'] === '') {
            throw new ApiException(200, 'invalid_me');
        }
        return $result;
    }

    public function sendMessage(
        string $chatId,
        #[\SensitiveParameter] string $text,
        ?Keyboard $keyboard = null
    ): array
    {
        return $this->send(['id' => self::id($chatId)], $text, $keyboard);
    }

    /** Единственное сообщение в личку автору комментария: не позже 7 дней, к эфиру – пока идёт. */
    public function sendPrivateReply(
        string $commentId,
        #[\SensitiveParameter] string $text,
        ?Keyboard $keyboard = null
    ): array {
        return $this->send(['comment_id' => self::id($commentId)], $text, $keyboard);
    }

    /** Публичный ответ под комментарием: ['id' => id нового комментария]. */
    public function replyToComment(string $commentId, #[\SensitiveParameter] string $text): array
    {
        self::assertNotEmpty($text);
        if (
            !mb_check_encoding($text, 'UTF-8') ||
            \diBot\Text::length($text) > self::COMMENT_MAX_CHARS
        ) {
            throw new \InvalidArgumentException('Text does not fit comment limit');
        }
        $data = $this->request('POST', '/' . self::id($commentId) . '/replies', body: [
            'message' => $text,
        ]);
        $id = $data['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            throw new ApiException(200, 'invalid_reply');
        }
        return ['id' => (string) $id];
    }

    /** @return Page<MediaItem> */
    public function getMedia(?string $after = null, int $limit = 25): Page
    {
        return $this->page(
            '/me/media',
            'id,caption,media_type,permalink,media_url,thumbnail_url,timestamp',
            $after,
            $limit,
            100,
            MediaItem::fromArray(...)
        );
    }

    /**
     * Только комментарии верхнего уровня, новые сначала; не больше 50 за запрос.
     * @return Page<Comment>
     */
    public function getComments(string $mediaId, ?string $after = null, int $limit = 50): Page
    {
        return $this->page(
            '/' . self::id($mediaId) . '/comments',
            'id,text,timestamp,from,username,parent_id,media',
            $after,
            $limit,
            50,
            static fn(array $item) => Comment::fromArray($item)
        );
    }

    /**
     * Читается только после согласия собеседника (написал, нажал кнопку или ice breaker);
     * без него – ApiException с reason consent_required.
     */
    public function getUserProfile(string $igsid): UserProfile
    {
        $profile = UserProfile::fromArray(
            ['id' => self::id($igsid)] +
                $this->request('GET', '/' . self::id($igsid), [
                    'fields' =>
                        'name,username,follower_count,is_user_follow_business,' .
                        'is_business_follow_user,is_verified_user',
                ])
        );
        return $profile ?? throw new ApiException(200, 'invalid_profile');
    }

    /** Без подписи и кнопок: текст и клавиатура – отдельным сообщением. */
    public function sendMedia(
        string $chatId,
        Media $media,
        Transport $transport,
        ?Keyboard $keyboard = null
    ): array {
        $this->assertText($media->caption, true);
        if ($keyboard !== null && $keyboard->render($this->platform()) !== []) {
            throw new \InvalidArgumentException('Instagram media cannot carry buttons');
        }
        if (
            $transport !== Transport::Url ||
            $media->publicUrl === '' ||
            ($media->kind === 'photo'
                ? $media->size > self::PHOTO_MAX_BYTES
                : $media->size > self::FILE_MAX_BYTES)
        ) {
            throw new \InvalidArgumentException('Instagram sends media only by public URL');
        }
        $payload = ['url' => $media->publicUrl];
        // Формы из справочника: картинки – списком attachments, файл – одиночным attachment.
        $message =
            $media->kind === 'photo'
                ? ['attachments' => [['type' => 'image', 'payload' => $payload]]]
                : ['attachment' => ['type' => 'file', 'payload' => $payload]];
        return $this->messages(['id' => self::id($chatId)], $message);
    }

    // Многоразовые attachment_id выдаёт только API страниц Facebook, не Instagram Login.
    public function sendFileId(
        string $chatId,
        string $kind,
        string $fileId,
        string $caption = '',
        ?Keyboard $keyboard = null
    ): array {
        throw new ApiException(0, 'unsupported');
    }

    public function editMessage(
        string $chatId,
        string $messageId,
        string $text,
        ?Keyboard $keyboard = null,
        bool $isCaption = false
    ): array {
        throw new ApiException(0, 'unsupported');
    }

    public function setCommands(array $commands): array
    {
        throw new ApiException(0, 'unsupported');
    }

    public function getUpdates(?string $cursor = null, int $timeout = 25): PollBatch
    {
        throw new ApiException(0, 'unsupported');
    }

    // Подтверждать postback не нужно: локальный успех без сети.
    public function answerCallback(string $callbackId, string $notification = ''): array
    {
        return ['ok' => true];
    }

    /**
     * Адрес вебхука один на приложение и задаётся в панели Meta, $url не используется:
     * метод подписывает аккаунт на события UPDATE_TYPES.
     */
    public function setWebhook(string $url): array
    {
        return $this->request('POST', '/me/subscribed_apps', [
            'subscribed_fields' => implode(',', self::UPDATE_TYPES),
        ]);
    }

    /**
     * Отписывает аккаунт от вебхуков приложения при любом $url: подписка одна на аккаунт.
     * Не вызывать из polling- и dev-окружений на том же приложении Meta.
     */
    public function deleteWebhook(?string $url = null): void
    {
        $this->request('DELETE', '/me/subscribed_apps');
    }

    public function rateLimitDelay(array $response, int $httpStatus = 0): int
    {
        return Errors::rateLimitDelay($response, $httpStatus);
    }

    /** Ссылка CDN из апдейта; ref у Instagram нет, продлить истёкшую ссылку нельзя. */
    public function downloadAttachment(string $ref, string $url = ''): \diBot\Attachment\Download
    {
        if ($url === '') {
            throw new \diBot\Exception\DownloadException(0, 'missing_url', true);
        }
        return (new \diBot\Attachment\Downloader($this->http, $this->config))->download($url);
    }

    /** Байты UTF-8, а не UTF-16: так меряет Instagram. */
    protected function assertText(string $text, bool $caption = false): void
    {
        parent::assertText($text, $caption);
        if (strlen($text) > self::TEXT_MAX_BYTES) {
            throw new \InvalidArgumentException('Text does not fit platform limit');
        }
    }

    private function send(
        array $recipient,
        #[\SensitiveParameter] string $text,
        ?Keyboard $keyboard
    ): array {
        self::assertNotEmpty($text);
        $this->assertText($text);
        $rendered = $keyboard?->render($this->platform()) ?? [];
        if (isset($rendered['buttons'])) {
            if (\diBot\Text::length($text) > self::TEMPLATE_TEXT_MAX_UNITS) {
                throw new \InvalidArgumentException('Text does not fit button template limit');
            }
            $message = [
                'attachment' => [
                    'type' => 'template',
                    'payload' => [
                        'template_type' => 'button',
                        'text' => $text,
                        'buttons' => $rendered['buttons'],
                    ],
                ],
            ];
        } else {
            $message = ['text' => $text] + $rendered;
        }
        return $this->messages($recipient, $message);
    }

    // Пустое сообщение Meta отклонит, а Sender иначе отправил бы его вместо медиа.
    private static function assertNotEmpty(string $text): void
    {
        if ($text === '') {
            throw new \InvalidArgumentException('Instagram text must not be empty');
        }
    }

    private function messages(array $recipient, #[\SensitiveParameter] array $message): array
    {
        return $this->request('POST', '/me/messages', body: [
            'recipient' => $recipient,
            'message' => $message,
        ]);
    }

    /**
     * @template T
     * @param \Closure(array): ?T $item
     * @return Page<T>
     */
    private function page(
        string $path,
        string $fields,
        ?string $after,
        int $limit,
        int $maxLimit,
        \Closure $item
    ): Page {
        if ($limit < 1 || $limit > $maxLimit) {
            throw new \InvalidArgumentException('Invalid page size');
        }
        $query = ['fields' => $fields, 'limit' => $limit];
        if ($after !== null) {
            if (!preg_match('/^[A-Za-z0-9_=-]{1,512}$/D', $after)) {
                throw new \InvalidArgumentException('Invalid page cursor');
            }
            $query['after'] = $after;
        }
        $data = $this->request('GET', $path, $query);
        if (!is_array($data['data'] ?? null)) {
            throw new ApiException(200, 'invalid_page');
        }
        $items = [];
        foreach ($data['data'] as $raw) {
            $parsed = is_array($raw) ? $item($raw) : null;
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }
        // Курсор after бывает и на последней странице: признак продолжения – paging.next.
        $paging = is_array($data['paging'] ?? null) ? $data['paging'] : [];
        $next = $paging['cursors']['after'] ?? null;
        return new Page(
            $items,
            isset($paging['next']) && is_string($next) && $next !== '' ? $next : null
        );
    }

    /** Вызов с обёрткой data[] (так показаны примеры справочника) и без неё. */
    private static function unwrap(array $data): array
    {
        return is_array($data['data'][0] ?? null) ? $data['data'][0] : $data;
    }

    /** Идентификаторы Instagram числовые; иное не должно попасть в путь запроса. */
    private static function id(string $id): string
    {
        if (!preg_match('/^[0-9]{1,32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Instagram ID');
        }
        return $id;
    }

    private function request(
        string $method,
        string $path,
        array $query = [],
        #[\SensitiveParameter] ?array $body = null
    ): array {
        // Токен идёт заголовком: перевод строки или пробел в нём – подмена заголовков.
        if (!preg_match('/^[A-Za-z0-9._|-]+$/D', $this->config->token)) {
            throw new ApiException(0, 'invalid_token');
        }
        $base = rtrim($this->config->baseUrl ?: self::DEFAULT_BASE_URL, '/');
        if (parse_url($base, PHP_URL_QUERY) !== null) {
            throw new \InvalidArgumentException('API base URL cannot contain query');
        }
        $headers = [
            'Authorization: Bearer ' . $this->config->token,
            'Content-Type: application/json',
        ];
        if ($base !== self::DEFAULT_BASE_URL) {
            if ($this->config->proxySecret === '') {
                throw new \InvalidArgumentException('Instagram proxy requires a secret');
            }
            $headers[] = 'X-Proxy-Auth: ' . $this->config->proxySecret;
        }
        $response = $this->exchange(
            new Request(
                $method,
                $base .
                    '/' .
                    self::GRAPH_VERSION .
                    $path .
                    ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''),
                $headers,
                $body === null
                    ? null
                    : json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $this->config->requestTimeout,
                $this->config->connectTimeout
            )
        );
        $error = Errors::classify($response);
        if ($error !== null) {
            if ($error->reason !== 'rate_limit') {
                $this->log('Instagram API rejected request', [
                    'status' => $response->status,
                    'reason' => $error->reason,
                    'shape' => \diBot\BodyShape::describe($body),
                ]);
            }
            throw $error;
        }
        return $response->json();
    }
}
