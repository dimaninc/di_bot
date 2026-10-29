<?php
namespace diBot\Instagram;

/**
 * @template T
 */
readonly final class Page
{
    /**
     * @param list<T> $items
     * @param ?string $next курсор after для следующей страницы; null – страниц больше нет
     */
    public function __construct(public array $items, public ?string $next)
    {
    }
}
