<?php
namespace diBot\Tests;

use diBot\Http\{Client, Request, Response};

final class FakeClient implements Client
{
    public array $requests = [];
    public function __construct(public array $responses = [])
    {
    }
    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if (!$this->responses) {
            throw new \LogicException('No response queued');
        }
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }
    public static function json(array $data, int $status = 200): Response
    {
        return new Response($status, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
