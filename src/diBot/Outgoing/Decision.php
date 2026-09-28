<?php
namespace diBot\Outgoing;
readonly final class Decision
{
    public function __construct(public Transport $transport, public string $reason = '')
    {
    }
}
