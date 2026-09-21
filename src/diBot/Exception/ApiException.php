<?php
namespace diBot\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $reason = 'api_error',
        public readonly bool $blocked = false
    ) {
        // Ответ API может повторить токен или пользовательский текст: наружу только классификация.
        parent::__construct('Bot API request failed: ' . $reason . ' (HTTP ' . $httpStatus . ')');
    }
}
