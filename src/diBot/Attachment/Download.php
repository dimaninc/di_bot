<?php
namespace diBot\Attachment;

readonly final class Download
{
    public function __construct(#[\SensitiveParameter] public string $bytes, public string $mime)
    {
    }
    public function __debugInfo(): array
    {
        return ['size' => strlen($this->bytes), 'mime' => $this->mime];
    }
}
