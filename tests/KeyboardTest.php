<?php
namespace diBot\Tests;
use diBot\{Button, Keyboard, Platform, Text};
use PHPUnit\Framework\TestCase;

final class KeyboardTest extends TestCase
{
    public function testUtf16NeverCutsSurrogatePair(): void
    {
        self::assertSame(3, Text::length('А😀'));
        self::assertSame('А', Text::truncate('А😀Б', 2));
        self::assertSame('А😀', Text::truncate('А😀Б', 3));
        $keyboard = new Keyboard([[Button::callback(str_repeat('😀', 33), 'value')]]);
        $text = $keyboard->render(Platform::Telegram)['inline_keyboard'][0][0]['text'];
        self::assertSame(str_repeat('😀', 32), $text);
    }
    public function testPlatformShapesAndRowLimits(): void
    {
        $buttons = array_fill(0, 9, Button::callback('Да', 'a'));
        $tg = (new Keyboard([$buttons]))->render(Platform::Telegram);
        self::assertCount(8, $tg['inline_keyboard'][0]);
        self::assertCount(1, $tg['inline_keyboard'][1]);
        $buttons[0] = Button::link('Link', 'https://example.com');
        $max = (new Keyboard([$buttons]))->render(Platform::Max);
        self::assertCount(3, $max['payload']['buttons']);
        self::assertSame('link', $max['payload']['buttons'][0][0]['type']);
        self::assertSame('a', $max['payload']['buttons'][0][1]['payload']);
    }
    public function testButtonMarkPreservesExistingIconAndHandlesInvalidUtf8(): void
    {
        self::assertSame('✅ Да', Button::callback('Да', 'a')->marked('positive')->text);
        self::assertSame('👍 Да', Button::callback('👍 Да', 'a')->marked('negative')->text);
        self::assertSame('Plain', Button::callback('Plain', 'a')->marked('unknown')->text);
        $button = Button::callback("\xff", 'a')->marked('positive');
        self::assertIsString($button->text);
    }
    public function testOversizedCallbackIsRejectedRatherThanChanged(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Keyboard([[Button::callback('x', str_repeat('a', 65))]]))->render(Platform::Telegram);
    }
    public function testTotalLimitRequiresPagination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Keyboard([array_fill(0, 101, Button::callback('x', 'x'))]))->render(
            Platform::Telegram
        );
    }
}
