# di_bot

PHP 8.3 transport for Telegram and MAX. No database, environment lookup, or CMS dependency.

```bash
composer require dimaninc/di_bot:^0.1
```

Until Packagist registration, add the VCS repository to the consuming project:

```json
{"repositories":[{"type":"vcs","url":"https://github.com/dimaninc/di_bot"}]}
```

## Send a message

```php
use diBot\{Button,Config,Keyboard,Platform};

$api = Platform::Telegram->api(new Config(token: $token));
$response = $api->sendMessage('12345', 'Choose an answer', new Keyboard([
    [Button::callback('Yes', 'answer:1')],
    [Button::link('Website', 'https://example.com')],
]));
```

The application supplies secrets. A custom Telegram `baseUrl` requires `proxySecret`;
requests to the standard API omit the proxy header. Requests never follow redirects.
Message text is plain text; the application splits texts that exceed adapter limits.
`setCommands()` accepts `[['command' => 'help', 'description' => 'Help']]` on both
platforms (up to 32 entries, names of 1–32 lowercase ASCII letters/digits/underscores
starting with a letter, descriptions of 1–128 UTF-16 units); an empty list removes the menu.
Invalid payloads and limits raise exceptions rather than silently changing callback data
or dropping buttons. Optional `Button::marked()` decoration tolerates cosmetic failures.

`Platform::Max` uses raw `Authorization` and the bundled CA only for
`platform-api2.max.ru`. Uploads to public HTTPS storage carry no API credentials or custom
CA. The cURL client rejects private/special IPs, pins DNS, disables proxies for uploads,
and caps response bodies at 8 MiB. `Http\Client` is injectable for tests.

## Media

```php
use diBot\Outgoing\{Media,Sender};

$result = (new Sender($api))->send('12345', 'Your result', fn() => new Media(
    kind: 'document', mime: 'application/pdf', size: filesize($path), localPath: $path
), fallbackText: 'Download your result: https://example.com/result.pdf');
```

`Policy` decides once, then `Sender` calls the transport. Media failure falls back to
text once; failure of the fallback propagates. Set `mediaEnabled: false` on `Sender`
to skip the media factory. Photo and document are supported. `sendFileId()` reuses a
Telegram file ID or a MAX token; storing these identifiers belongs to the application.

Both adapters use a conservative **7 MiB** upload budget, compatible with an 8 MiB
Telegram proxy buffer. This is a library limit, not MAX's native upload ceiling.
Local files are preferred and their actual size is checked before upload. MAX photos
up to 5 MiB may instead use a supplied public URL. Telegram uses local uploads because
it may not reach the application's public host. Oversized captions cause text fallback.

MAX `attachment.not.ready` retries use 2/4/8 second delays only when
`Config(retryMediaInCli: true)` is explicitly set and `PHP_SAPI === 'cli'`.
There are no automatic retries for ambiguous network errors or ordinary sends.

## Updates and webhooks

`Telegram\Update::fromArray()` and `Max\Update::fromArray()` return a shared update
or `null` for unsupported data. IDs are strings, commands include `/start` payload and
an optional `commandTarget` from `/command@BotName`. The application filters addressed
commands, group chats, and duplicate events. MAX deduplication IDs use message/callback
identity, not a timestamp alone. `bot_started` maps to `start`.

Extend `AbstractController` and implement `handle(AbstractUpdate $update): void`.
Call `emitWebhook($rawBody, $suppliedSecret)` to emit HTTP 200 and `{"ok":true}`.
Use `webhook()` instead when your framework owns the HTTP response. The application
extracts Telegram's `X-Telegram-Bot-Api-Secret-Token` header or MAX's secret URL segment.
Empty token or webhook secret disables processing. Comparison uses `hash_equals`.
Malformed JSON, handler exceptions, and logger failures still acknowledge the webhook.

The hosting entry point must also guard failures **before the controller is constructed**,
including environment parsing and database bootstrap. The package cannot intercept them.
No raw payload values, tokens, API error descriptions, or exception messages are logged.
`ApiException` exposes HTTP status, a bounded reason and a conservative blocked flag;
its message contains no third-party response text.

## Polling

Implement `CursorStore` outside the package. `load()` returns a string cursor or `null`
for an explicitly initialized new store; absent, unreadable and corrupt stores must
throw. `save()` must throw on failure. Hold a lock for the entire poller lifetime.

`poll($store, maxBatches: 0)` validates and tests the store before removing webhooks.
It saves the platform cursor after successful processing of the whole batch. A crash
before checkpointing may redeliver events: the application still needs deduplication.
Telegram advances to `last update_id + 1`; MAX uses the response `marker` unchanged.
A store or handler error stops the loop. An optional `shouldContinue` closure and finite
`maxBatches` allow graceful termination. Override `pollingStarted()` to send a test prompt
once polling has removed webhooks. Use a separate test bot: polling removes its webhooks.

## Limits and compatibility

Adapter constants describe enforced limits. Labels are cut at UTF-16 boundaries.
The 64-unit label cap is a library UX policy; Telegram does not document that cap.
MAX link rows are split at 3 buttons, callback-only rows at 7; keyboards have at most
30 rows and 210 buttons. Telegram uses 8 per row and 100 total as a conservative policy.
The caller must paginate when the total limit is exceeded.

Instagram is reserved in `Platform`, but has no adapter. No business logic or storage
implementation is included. `setWebhook()` does not choose a production URL: that is
the application's responsibility.

## Development

```bash
composer install
composer test
composer validate --strict
```

Tests use synthetic fixtures and a fake HTTP boundary: no live API or secrets are needed.
Certificate tests pin the three roots and fail 90 days before expiry. Runtime smoke tests
with real buttons remain the responsibility of consuming applications.

Sources checked on 2026-09-20: [Telegram Bot API](https://core.telegram.org/bots/api),
[MAX overview](https://dev.max.ru/docs-api),
[MAX uploads](https://dev.max.ru/docs-api/methods/POST/uploads),
[MAX polling](https://dev.max.ru/docs-api/methods/GET/updates),
[MAX webhook removal](https://dev.max.ru/docs-api/methods/DELETE/subscriptions).
[Package specification](SPEC.md).
