<?php
namespace diBot\Exception;

final class MediaNotReadyException extends ApiException
{
    public function __construct(
        int $httpStatus,
        public readonly string $kind,
        #[\SensitiveParameter] private readonly string $fileId
    ) {
        parent::__construct($httpStatus, 'attachment.not.ready');
    }

    /** Токен предназначен для хранения и sendFileId(), а не для логирования. */
    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function __debugInfo(): array
    {
        return [
            'httpStatus' => $this->httpStatus,
            'reason' => $this->reason,
            'kind' => $this->kind,
            'fileId' => '[redacted]',
        ];
    }
}
