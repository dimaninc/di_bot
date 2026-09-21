<?php
namespace diBot\Outgoing;

readonly final class Media
{
    public function __construct(
        public string $kind,
        public string $mime,
        public int $size,
        public string $publicUrl = '',
        public string $localPath = '',
        public string $filename = '',
        public string $caption = ''
    ) {
        if (
            !in_array($kind, ['photo', 'document'], true) ||
            $size < 0 ||
            ($publicUrl === '' && $localPath === '')
        ) {
            throw new \InvalidArgumentException('Invalid outgoing media descriptor');
        }
        if ($publicUrl !== '') {
            \diBot\Config::assertHttpsUrl($publicUrl);
        }
    }

    public function withCaption(string $caption): self
    {
        return new self(
            $this->kind,
            $this->mime,
            $this->size,
            $this->publicUrl,
            $this->localPath,
            $this->filename,
            $caption
        );
    }
}
