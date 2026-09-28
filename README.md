# di_bot

PHP 8.3 transport for Telegram and MAX. No database, environment lookup, or CMS dependency.

The first release is pending review and merge. No released version is available yet.
For development, check out the pull-request branch and run `composer install`.
Applications should pin a published version after the first release.

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
requests to the standard API omit the proxy header. API requests never follow redirects.
Message text is plain text; the application splits texts that exceed adapter limits.
`setCommands()` accepts `[['command' => 'help', 'description' => 'Help']]` on both
platforms (up to 32 entries, names of 1–32 lowercase ASCII letters/digits/underscores
starting with a letter, descriptions of 1–128 UTF-16 units); an empty list removes the menu.
Invalid payloads and limits raise exceptions rather than silently changing callback data
or dropping buttons. Optional `Button::marked()` decoration tolerates cosmetic failures.

`Platform::Max` uses raw `Authorization` and the bundled CA only for
`platform-api2.max.ru`. Uploads to public HTTPS storage carry no API credentials or custom
CA. The cURL client rejects private/special IPs, pins DNS, disables proxies for uploads
and public downloads, and caps API response bodies at 8 MiB. `Http\Client` is injectable for tests.

## Media

```php
use diBot\Outgoing\{Media,Sender};

$text = 'Your result';
$result = (new Sender($api))->send('12345', $text, fn() => new Media(
    kind: 'document', mime: 'application/pdf', size: filesize($path), localPath: $path
), fallbackText: $text . "\n\nDownload: https://example.com/result.pdf");
```

`Policy` decides once, then `Sender` calls the transport. A definitive media refusal
falls back to text once; failure of the fallback propagates. Network errors, invalid
JSON and HTTP 5xx have an uncertain delivery outcome: `Sender` rethrows without sending
fallback text, even if the failure happened before the final send. This conservative
rule avoids duplicate messages when a successful send loses its response.
Rate limits and `MediaNotReadyException` also propagate without text fallback.
`send(..., fallbackWhenBlocked: false)` rethrows blocked-chat errors instead of trying
text in the same chat; the default is `true` to allow text when media permissions differ.
Use `false` for deliveries where a second request is unwanted. A media 403 alone must
not be treated as proof that a user stopped the bot, especially in groups.
Set `mediaEnabled: false` on `Sender`
to skip the media factory. Photo and document are supported. `sendFileId()` reuses a
Telegram file ID or a MAX token; storing these identifiers belongs to the application.

Both adapters use a conservative **7 MiB** upload budget, compatible with an 8 MiB
Telegram proxy buffer. This is a library limit, not MAX's native upload ceiling.
Local files are preferred and their actual size is checked before upload. MAX photos
up to 5 MiB may instead use a supplied public URL. Telegram uses local uploads because
it may not reach the application's public host. A `caption_too_long` decision skips
media and sends the original `$text` with its keyboard, ignoring `$fallbackText`.
This preserves the main text when only the caption limit is exceeded. The text must
still fit `TEXT_MAX_UNITS`; otherwise sending fails without replacing it with a shorter
fallback. Splitting longer text or sending media and text separately belongs to the app.
For other fallback paths, `$fallbackText` replaces `$text` completely: include all text
that must reach the user, as in the example above.

MAX `attachment.not.ready` retries use 2/4/8 second delays only when
`Config(retryMediaInCli: true)` is explicitly set and `PHP_SAPI === 'cli'`.
There are no automatic retries for ambiguous delivery errors or ordinary sends.
When an uploaded MAX file is still processing, `MediaNotReadyException` exposes its
`kind` and `getFileId()` after the retry budget (immediately when retries are disabled).
`Sender` preserves that exception. Catch it **inside your update handler**, persist the
file ID and schedule `sendFileId()` later without uploading again. Queueing and durable
storage belong to the application. A photo sent by URL has no reusable upload token;
its `attachment.not.ready` remains a plain `ApiException` and can fall back to text.
Never log the file ID or signed download URLs.

`editMessage(..., keyboard: null)` and an empty `Keyboard` remove existing buttons.
MAX reads the current message before every edit. Without `isCaption: true`, any
non-keyboard attachment causes `ApiException(reason: 'caption_required')` before PUT.
Thus accidentally editing a media message as text cannot remove its media. A missing
or malformed message/attachment list also aborts the edit.
Use `isCaption: true` for messages with media: MAX retains photo/document tokens, then
replaces the buttons; Telegram edits the caption. GET and PUT are separate requests;
the application should serialize edits to the same message.
MAX response-only fields (`photo_id`, `url`) are not echoed into the edit request.
Malformed attachments, missing tokens and unsupported media abort before PUT so an
edit cannot silently delete them. With no media,
MAX removes buttons using `attachments: []`, not an empty keyboard attachment.

## Incoming attachments

`AbstractUpdate::attachments` contains photo/document `Attachment` objects with
`kind`, `ref`, `url`, `mime`, `size` and `filename`. Telegram selects the largest photo
variant; MAX preserves the temporary CDN URL. Callback updates never expose the old
message's attachments as new uploads. Names, MIME and reported sizes in updates are
untrusted metadata; `KindResolver` provides descriptive labels only.

```php
use diBot\Exception\DownloadException;

foreach ($update->attachments as $attachment) {
    try {
        $file = $api->downloadAttachment($attachment->ref, $attachment->url);
        // $file->bytes and $file->mime; storage and content validation belong to the app.
    } catch (DownloadException $e) {
        // $e->permanent: retrying this source is pointless.
        // $e->rateLimitDelay(): earliest retry in seconds, or zero.
    }
}
```

Telegram obtains a fresh `file_path` with `getFile`, then fetches `/file/bot…/…` from
the configured API base. A proxy must support that path as well as API calls. Secret
headers never follow redirects. MAX downloads require a CDN URL: the reference alone
cannot renew an expired URL. Public downloads allow at most three absolute HTTPS
redirects; each hop is checked and DNS-pinned by `CurlClient`. No API credentials or
custom CA bundle are sent to a CDN.

`Config(maxDownloadBytes: 5 * 1024 * 1024, downloadTimeout: 30)` sets the default byte
cap and timeout per request. The byte cap is configurable up to 20 MiB. cURL aborts
while reading an oversized body, regardless of metadata or Content-Length. Files are
returned in memory; the library does not write them to disk or update database rows.
Expired MAX URLs (403/404/410), invalid URLs and oversized bodies are permanent download
errors. Network errors, empty responses, partial responses and HTTP 5xx are retryable;
a caller should bound retries. `Download::mime` is the HTTP Content-Type, not a guarantee
that bytes are safe to serve inline. A 3xx without a usable Location, including 304,
is a temporary `invalid_redirect`, not a permanently blocked URL. TLS configuration
failures are permanent until configuration is corrected. Telegram configuration errors
raised during `getFile` are wrapped in `DownloadException` (`invalid_configuration`).

## Blocked chats and rate limits

MAX `chat.denied`, `chat.blocked`, `bot.blocked` and `user.blocked` set `ApiException::blocked`.
Free-form MAX error text and arbitrary codes containing `block` do not. Telegram 403
sets this flag for forbidden chats, including blocked/deactivated users; it is a delivery
restriction, not proof of a particular user action. `chat.denied` is an empirical code;
the other three MAX codes are compatibility assumptions without live confirmation.
These codes and `too.many.requests` are not guaranteed by the published MAX reference.
Unit fixtures verify classification only; HTTP 429 remains the documented rate-limit
signal independently of the string code. An unknown code is an ordinary API error.

`$api->rateLimitDelay($response, $httpStatus)` classifies raw platform responses;
callers using the API normally read `$exception->rateLimitDelay()`. Telegram's
`parameters.retry_after` and numeric `Retry-After` headers are preserved. HTTP 429 and
MAX `too.many.requests` use a one-second minimum when no valid delay is supplied.
Send operations and downloads never sleep or retry automatically on rate limits:
workers should reschedule after that delay. `Sender` does not immediately try text.
MAX `answerCallback($id)` with an empty notification is a successful no-op (`['ok'=>true]`);
a nonempty notification still calls `/answers`. The no-op is a local result, not an
acknowledgement from MAX. Its effect on client loading indicators needs live acceptance.
The reference describes notifications but currently omits the `notification` field from
its request schema; the SDK exposes it. Validate both paths on supported MAX clients.

## Updates and webhooks

`Telegram\Update::fromArray()` and `Max\Update::fromArray()` return a shared update
or `null` for unsupported data. IDs are strings, commands include `/start` payload and
an optional `commandTarget` from `/command@BotName`. The application filters addressed
commands, group chats, and duplicate events. MAX deduplication IDs use message/callback
identity, not a timestamp alone. `bot_started` maps to `start`; a missing/invalid
timestamp makes that event malformed
and it is ignored, rather than assigning all starts the same deduplication ID.
MAX has no documented locale in User: `userProfile['language']` is empty when absent.
Do not assume TamTam's `user_locale` field exists in MAX or overwrite a known language
with this empty value.

Extend `AbstractController` and implement `handle(AbstractUpdate $update): void`.
Call `emitWebhook($rawBody, $suppliedSecret)` to emit HTTP 200 and `{"ok":true}`.
Use `webhook()` instead when your framework owns the HTTP response. The application
extracts Telegram's `X-Telegram-Bot-Api-Secret-Token` or MAX's
`X-Max-Bot-Api-Secret` header and passes its value as `$suppliedSecret`.
`setWebhook()` requires `Config::webhookSecret`: Telegram accepts 1–256 and MAX 5–256
ASCII letters, digits, underscores or hyphens. MAX sends it as `secret` in the subscription
body. Do not embed the secret in the endpoint URL.

MAX `setWebhook()` sends POST for the requested URL; it does not itself delete
other subscriptions. Automatic replacement is not assumed. When intentionally replacing all
endpoints, call `deleteWebhook()` before `setWebhook($newUrl)`; this removes all current
subscriptions and creates a delivery gap if registration fails. Verify subscriptions
via GET `/subscriptions` after changing the URL.
Empty token or webhook secret disables processing. Comparison uses `hash_equals`.
Malformed JSON, handler exceptions, and logger failures still acknowledge the webhook.

The hosting entry point must also guard failures **before the controller is constructed**,
including environment parsing and database bootstrap. The package cannot intercept them.
No raw payload values, tokens, API error descriptions, or exception messages are logged.
`ApiException` exposes HTTP status, a bounded reason and a conservative blocked flag;
its message contains no third-party response text. Known HTTP-client reasons survive
`exchange()`: `tls_untrusted_ca` (cURL 60), `tls_ca_file` (77),
`tls_client_certificate` (58), `response_too_large`, `curl_init`, `network_error`.
Unknown client exceptions/reasons are scrubbed to `network_error`, with no previous
exception. Logs include the safe reason and HTTP status; TLS setup failures are not
retried by polling.

## Polling

Implement `CursorStore` outside the package. `load()` returns a string cursor or `null`
for an explicitly initialized new store; absent, unreadable and corrupt stores must
throw. `save()` must throw on failure. Hold a lock for the entire poller lifetime.

`poll($store, maxBatches: 0)` validates and tests the store before removing webhooks.
It saves the platform cursor after attempting every update in the batch. Handler
exceptions are logged without message text and skipped, matching the webhook policy;
later updates continue. Persist any required retries inside the handler before returning. A crash
before checkpointing may redeliver events: the application still needs deduplication.
Telegram advances to `max(valid update_id) + 1`; MAX uses the response `marker` unchanged.
Malformed Telegram IDs are logged without payload and skipped when valid IDs allow
progress. A malformed batch with no advancing valid ID fails with `invalid_update_id`:
there is no safe offset to infer; repair the upstream response before restarting.
Malformed MAX entries are skipped using the independently supplied marker.
An empty MAX batch may omit `marker` or return null: the stored cursor stays unchanged.
A nonempty batch without a marker fails rather than silently losing position.
Store errors stop the loop. Network errors, HTTP 5xx, invalid JSON and rate limits in
`getUpdates()` receive at most three retries per batch: 1/2/4 seconds for uncertain
responses, or the platform delay for rate limits. Retries reuse the same cursor and do not consume `maxBatches`. After
the budget is exhausted the exception propagates with the cursor unchanged. API
rejections and setup errors (webhook removal, initial prompt) are not retried. An optional `shouldContinue` closure and finite
`maxBatches` allow graceful termination. Override `pollingStarted()` to send a test prompt
once polling has removed webhooks. Use a separate test bot: polling removes its webhooks.

MAX documents polling as a development/testing interface; use webhooks for production.

## Application integration

Handle `MediaNotReadyException` and rate-limit
exceptions where media is sent; they no longer trigger an immediate text fallback.
Polling now skips handler failures, and custom `AbstractBotApi` subclasses must implement
`downloadAttachment()` and `rateLimitDelay()`. Custom HTTP clients should honor
`Request::maxResponseBytes` and the public-download guard and return lowercase keys
in `Response::headers`. Instagram, outgoing video and button intents remain unsupported.

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
Certificate tests pin the three roots and fail 90 days before expiry.
For an optional credential-free network check, run `php scripts/curl-smoke.php`:
it uses real libcurl, checks the connected peer against CURLOPT_RESOLVE and sets an
unreachable environment proxy to verify that CDN requests bypass it. It only makes
HEAD requests and does not validate file upload or download contents.
Live bot checks are listed in [ACCEPTANCE.md](ACCEPTANCE.md).

Sources checked on 2026-09-28: [Telegram Bot API](https://core.telegram.org/bots/api),
[MAX overview](https://dev.max.ru/docs-api),
[MAX uploads](https://dev.max.ru/docs-api/methods/POST/uploads),
[MAX polling](https://dev.max.ru/docs-api/methods/GET/updates),
[MAX webhook registration](https://dev.max.ru/docs-api/methods/POST/subscriptions),
[MAX webhook removal](https://dev.max.ru/docs-api/methods/DELETE/subscriptions),
[MAX message editing](https://dev.max.ru/docs-api/methods/PUT/messages),
[MAX callback answers](https://dev.max.ru/docs-api/methods/POST/answers).
[Package specification](SPEC.md).
