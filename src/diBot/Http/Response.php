<?php
namespace diBot\Http;

use diBot\Exception\ApiException;

readonly final class Response
{
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
    }

    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException($this->status, 'invalid_json');
        }
        if (!is_array($data)) {
            throw new ApiException($this->status, 'invalid_json');
        }
        return $data;
    }
}
