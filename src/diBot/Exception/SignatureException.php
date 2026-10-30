<?php
namespace diBot\Exception;

/** Подпись вебхука отсутствует или не сходится: тело не от площадки, обрабатывать нельзя. */
final class SignatureException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid webhook signature');
    }
}
