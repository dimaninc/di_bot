<?php
namespace diBot;

interface CursorStore
{
    // null – явно инициализированная новая позиция. Недоступность/порча – исключение.
    public function load(): ?string;
    public function save(?string $cursor): void;
}
