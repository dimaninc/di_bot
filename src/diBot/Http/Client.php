<?php
namespace diBot\Http;

interface Client
{
    public function send(Request $request): Response;
}
