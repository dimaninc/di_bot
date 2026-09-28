<?php
namespace diBot\Attachment;

readonly final class Attachment
{
    public function __construct(
        public string $kind,
        #[\SensitiveParameter] public string $ref,
        #[\SensitiveParameter] public string $url = '',
        public string $mime = '',
        public int $size = 0,
        public string $filename = ''
    ) {
    }
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'size' => $this->size];
    }
}
