<?php
namespace diBot\Instagram;

use diBot\Exception\ApiException;
use diBot\Http\Response;

/**
 * Классификация ошибок Graph API по code/error_subcode. Текст ошибки Meta наружу не
 * выходит: только причина, HTTP-статус и задержка.
 */
final class Errors
{
    // Код 4 – приложение, 17 – пользователь, 32 – Pages, 613 – особый лимит, 80002 и
    // 80006 – Business Use Case. HTTP 429 Meta не документирует, но и его уважаем.
    private const RATE_LIMIT_CODES = [4, 17, 32, 613, 80002, 80006];
    // 613: «сочтено навязчивым» и повтор opt-in – не throttling, ждать бесполезно.
    private const NOT_RATE_LIMIT_SUBCODES = [2018338, 1893016];
    // Лимиты Meta считаются окнами в часы: секунда без подсказки площадки бессмысленна.
    public const MIN_RATE_LIMIT_DELAY = 60;
    // Подсказка дольше суток – скорее мусор в заголовке, чем реальный лимит.
    private const MAX_HEADER_DELAY = 24 * 60 * 60;

    public static function rateLimitDelay(array $response, int $httpStatus = 0): int
    {
        $error = is_array($response['error'] ?? null) ? $response['error'] : [];
        $code = $error['code'] ?? null;
        $subcode = $error['error_subcode'] ?? null;
        if (
            (in_array($code, self::RATE_LIMIT_CODES, true) &&
                !in_array($subcode, self::NOT_RATE_LIMIT_SUBCODES, true)) ||
            $httpStatus === 429
        ) {
            return self::MIN_RATE_LIMIT_DELAY;
        }
        return 0;
    }

    /** null – ответ успешный. */
    public static function classify(Response $response): ?ApiException
    {
        try {
            $data = $response->json();
        } catch (ApiException $e) {
            if ($response->status !== 429) {
                throw $e;
            }
            $data = [];
        }
        $delay = self::rateLimitDelay($data, $response->status);
        if ($delay > 0) {
            return new ApiException(
                $response->status,
                'rate_limit',
                retryAfter: max($delay, self::headerDelay($response->headers))
            );
        }
        if ($response->status >= 200 && $response->status < 300 && !isset($data['error'])) {
            return null;
        }
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = $error['code'] ?? null;
        $subcode = $error['error_subcode'] ?? null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $reason = match (true) {
            $code === 190 => 'invalid_token',
            $code === 10 && in_array($subcode, [2018278, 2534022], true) => 'window_closed',
            $subcode === 2534025 => 'reply_unavailable',
            // Код у отказа без согласия не документирован, только текст: по нему и решаем,
            // сам текст в исключение не попадает.
            stripos($message, 'consent is required') !== false => 'consent_required',
            // Клиент не включил «Доступ к сообщениям» в настройках Instagram; тоже только текст.
            $code === 200 && stripos($message, 'disabled access to Instagram Direct') !== false
                => 'messaging_disabled',
            default => 'api_error',
        };
        // 551 – «не принимает сообщения от вас», 2018108 – получатель сейчас недоступен.
        $blocked = $code === 551 || $subcode === 2018108;
        return new ApiException($response->status, $reason, $blocked);
    }

    /** Секунды из X-Business-Use-Case-Usage (минуты) или Retry-After; 0 – подсказки нет. */
    private static function headerDelay(array $headers): int
    {
        $seconds = 0;
        $usage = json_decode((string) ($headers['x-business-use-case-usage'] ?? ''), true);
        foreach (is_array($usage) ? $usage : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $minutes = is_array($entry) ? $entry['estimated_time_to_regain_access'] ?? 0 : 0;
                if (is_int($minutes) && $minutes > 0) {
                    $seconds = max($seconds, min($minutes * 60, self::MAX_HEADER_DELAY));
                }
            }
        }
        if (isset($headers['retry-after'])) {
            $seconds = max(
                $seconds,
                min(ApiException::retrySeconds($headers['retry-after']), self::MAX_HEADER_DELAY)
            );
        }
        return $seconds;
    }
}
