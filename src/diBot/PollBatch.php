<?php
namespace diBot;
readonly final class PollBatch
{
    /** @param list<AbstractUpdate> $updates */
    public function __construct(public array $updates, public ?string $cursor)
    {
    }
}
