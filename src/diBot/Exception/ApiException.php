<?php
namespace diBot\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $reason = 'api_error',
        public readonly bool $blocked = false,
        public readonly int $retryAfter = 0
    ) {
        // Ответ API может повторить токен или пользовательский текст: наружу только классификация.
        parent::__construct('Bot API request failed: ' . $reason . ' (HTTP ' . $httpStatus . ')');
    }
    /** Секунды до следующей попытки; ноль означает отсутствие throttling. */
    public function rateLimitDelay(): int
    {
        return $this->retryAfter;
    }

    public static function retrySeconds(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            return 1;
        }
        $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $seconds === false ? 1 : $seconds;
    }

    public function isDeliveryUncertain(): bool
    {
        // Без подтверждённого ответа даже запасной текст может стать дублем.
        return $this->httpStatus >= 500 ||
            in_array($this->reason, ['network_error', 'invalid_json'], true);
    }
}
