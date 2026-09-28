<?php
namespace diBot\Exception;

final class DownloadException extends ApiException
{
    public function __construct(
        int $httpStatus,
        string $reason,
        public readonly bool $permanent,
        int $retryAfter = 0
    ) {
        parent::__construct($httpStatus, $reason, retryAfter: $retryAfter);
    }
}
