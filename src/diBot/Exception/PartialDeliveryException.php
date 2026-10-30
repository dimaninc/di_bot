<?php
namespace diBot\Exception;

/**
 * Медиа доставлено, а текст вторым сообщением – нет (площадка без подписей). Повтор всего
 * send() продублирует файл: повторять только текст.
 */
final class PartialDeliveryException extends ApiException
{
    public function __construct(ApiException $text, public readonly array $mediaResponse)
    {
        parent::__construct($text->httpStatus, $text->reason, $text->blocked, $text->retryAfter);
    }
}
