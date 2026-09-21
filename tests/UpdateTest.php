<?php
namespace diBot\Tests;

use diBot\Telegram\Update as Telegram;
use diBot\Max\Update as Max;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    public static function telegram(string $text = '/start survey__src'): array
    {
        return [
            'update_id' => 12,
            'message' => [
                'message_id' => 8,
                'from' => [
                    'id' => '9007199254740993',
                    'first_name' => 'Имя',
                    'language_code' => 'ru',
                ],
                'chat' => ['id' => '9007199254740993', 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }
    public static function max(string $id = 'mid-1'): array
    {
        return [
            'update_type' => 'message_created',
            'timestamp' => 1,
            'message' => [
                'sender' => ['user_id' => '17', 'name' => 'Имя'],
                'recipient' => ['chat_id' => '18', 'chat_type' => 'dialog'],
                'body' => ['mid' => $id, 'text' => '/start survey__src'],
            ],
        ];
    }
    public function testTelegramMessageAndCommands(): void
    {
        $u = Telegram::fromArray(self::telegram('/start@MyBot survey__src'));
        self::assertSame('9007199254740993', $u->userId);
        self::assertSame('start', $u->command);
        self::assertSame('MyBot', $u->commandTarget);
        self::assertSame('survey__src', $u->commandPayload);
        self::assertSame('ru', $u->userProfile['language']);
        self::assertTrue($u->isPrivateChat);
        self::assertSame('', Telegram::fromArray(self::telegram('/some-slug'))->command);
    }
    public function testTelegramCallbackUsesActorNotBot(): void
    {
        $msg = self::telegram()['message'];
        $msg['from'] = ['id' => 999];
        $u = Telegram::fromArray([
            'update_id' => 13,
            'callback_query' => [
                'id' => 'cq',
                'data' => 'a:1:2:3',
                'from' => ['id' => 42],
                'message' => $msg,
            ],
        ]);
        self::assertTrue($u->isCallback());
        self::assertSame('42', $u->userId);
        self::assertSame('a:1:2:3', $u->payload);
        self::assertSame('', $u->command);
        self::assertNull(Telegram::fromArray(['callback_query' => ['id' => 'inline']]));
    }
    public function testMaxTimestampIsNotUsedAsUniqueId(): void
    {
        $a = Max::fromArray(self::max());
        $b = Max::fromArray(self::max('mid-2'));
        self::assertNotSame($a->updateId, $b->updateId);
        self::assertSame($a->updateId, Max::fromArray(self::max())->updateId);
        self::assertSame('Имя', $a->userProfile['first_name']);
        self::assertSame('start', $a->command);
        self::assertTrue($a->isPrivateChat);
    }
    public function testMaxStartedAndCallback(): void
    {
        $u = Max::fromArray([
            'update_type' => 'bot_started',
            'timestamp' => 10,
            'chat_id' => 18,
            'user' => ['user_id' => 17],
            'payload' => 'survey__ad',
        ]);
        self::assertSame('start', $u->command);
        self::assertSame('survey__ad', $u->commandPayload);
        $raw = self::max();
        $raw['update_type'] = 'message_callback';
        $raw['callback'] = ['callback_id' => 'c', 'payload' => 'go', 'user' => ['user_id' => 19]];
        $u = Max::fromArray($raw);
        self::assertSame('19', $u->userId);
        self::assertSame('go', $u->payload);
        self::assertTrue($u->isCallback());
    }
    public function testMalformedUpdatesAreIgnored(): void
    {
        foreach (
            [
                [],
                ['message' => false],
                ['message' => 'secret'],
                ['message' => ['chat' => []]],
                ['update_type' => 'message_created', 'message' => ['body' => false]],
            ]
            as $data
        ) {
            self::assertNull(Telegram::fromArray($data));
            self::assertNull(Max::fromArray($data));
        }
        $raw = self::telegram();
        $raw['message']['chat']['type'] = 'group';
        self::assertFalse(Telegram::fromArray($raw)->isPrivateChat);
    }
}
