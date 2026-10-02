<?php
namespace diBot\Tests;

use diBot\AbstractUpdate;
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
    public static function telegramMembership(
        string $status,
        string $chatType = 'private',
        array $old = ['status' => 'member']
    ): array {
        return [
            'update_id' => 30,
            'my_chat_member' => [
                'chat' => ['id' => 501, 'type' => $chatType],
                'from' => ['id' => 77, 'first_name' => 'Имя', 'language_code' => 'ru'],
                'date' => 1700000000,
                'old_chat_member' => $old + ['user' => ['id' => 1]],
                'new_chat_member' => ['status' => $status, 'user' => ['id' => 1]],
            ],
        ];
    }
    public function testTelegramMembershipEvents(): void
    {
        $u = Telegram::fromArray(self::telegramMembership('kicked'));
        self::assertSame(AbstractUpdate::MEMBERSHIP_STOPPED, $u->membership);
        self::assertSame('30', $u->updateId);
        self::assertSame('501', $u->chatId);
        self::assertSame('77', $u->userId);
        self::assertTrue($u->isPrivateChat);
        self::assertSame('Имя', $u->userProfile['first_name']);
        self::assertSame('ru', $u->userProfile['language']);
        self::assertSame('', $u->text);
        self::assertSame('', $u->command);
        self::assertSame('', $u->messageId);
        self::assertFalse($u->isCallback());
        self::assertSame([], $u->attachments);
        self::assertSame([], $u->otherContent);
        self::assertSame(
            AbstractUpdate::MEMBERSHIP_STARTED,
            Telegram::fromArray(self::telegramMembership('member', old: ['status' => 'kicked']))
                ->membership
        );
        self::assertSame(
            AbstractUpdate::MEMBERSHIP_STOPPED,
            Telegram::fromArray(self::telegramMembership('left'))->membership
        );
        // Событие – смена присутствия, а не статуса.
        foreach (
            [
                ['member', ['status' => 'member']],
                ['kicked', ['status' => 'left']],
                ['member', ['status' => 'administrator']],
                ['member', ['status' => 'restricted', 'is_member' => true]],
            ]
            as [$new, $old]
        ) {
            self::assertNull(
                Telegram::fromArray(self::telegramMembership($new, old: $old)),
                json_encode($old) . " -> $new"
            );
        }
        self::assertSame(
            AbstractUpdate::MEMBERSHIP_STARTED,
            Telegram::fromArray(
                self::telegramMembership(
                    'member',
                    old: ['status' => 'restricted', 'is_member' => false]
                )
            )->membership
        );
        // Группы и каналы – не события собеседника: не отдаются (у MAX их нет в подписке).
        foreach (['group', 'supergroup', 'channel'] as $type) {
            self::assertNull(Telegram::fromArray(self::telegramMembership('kicked', $type)), $type);
            self::assertNull(
                Telegram::fromArray(
                    self::telegramMembership('member', $type, ['status' => 'left'])
                ),
                $type
            );
        }
        $raw = self::telegramMembership('kicked');
        unset($raw['my_chat_member']['new_chat_member']['status']);
        self::assertNull(Telegram::fromArray($raw), 'нет статуса – битое событие');
        foreach (['chat', 'from', 'old_chat_member', 'new_chat_member'] as $key) {
            $raw = self::telegramMembership('kicked');
            $raw['my_chat_member'][$key] = 'x';
            self::assertNull(Telegram::fromArray($raw));
        }
        self::assertNull(Telegram::fromArray(['update_id' => 1, 'my_chat_member' => 'x']));
        $raw = self::telegramMembership('kicked');
        unset($raw['update_id']);
        self::assertNull(Telegram::fromArray($raw));
        // Обычные сообщения членство не выставляют.
        self::assertSame('', Telegram::fromArray(self::telegram())->membership);
    }
    public function testMaxBotStoppedAndStartedMembership(): void
    {
        $raw = ['chat_id' => 18, 'user' => ['user_id' => 17, 'name' => 'Имя'], 'timestamp' => 10];
        $stopped = Max::fromArray(['update_type' => 'bot_stopped'] + $raw);
        self::assertSame(AbstractUpdate::MEMBERSHIP_STOPPED, $stopped->membership);
        self::assertSame('18', $stopped->chatId);
        self::assertSame('17', $stopped->userId);
        self::assertTrue($stopped->isPrivateChat);
        self::assertSame('Имя', $stopped->userProfile['first_name']);
        self::assertSame('', $stopped->text);
        self::assertSame('', $stopped->command);
        $started = Max::fromArray(['update_type' => 'bot_started'] + $raw);
        // bot_started – это /start (первый контакт или возврат), а не событие членства:
        // обработчик, пропускающий события членства, не должен терять /start с payload.
        self::assertSame('', $started->membership);
        self::assertSame('start', $started->command);
        self::assertSame('/start', $started->text);
        // Одинаковые чат/пользователь/время у старта и остановки – разные события.
        self::assertNotSame($started->updateId, $stopped->updateId);
        self::assertSame(
            $stopped->updateId,
            Max::fromArray(['update_type' => 'bot_stopped'] + $raw)->updateId
        );
        foreach ([null, '', -1, 'bad'] as $timestamp) {
            self::assertNull(
                Max::fromArray(['update_type' => 'bot_stopped', 'timestamp' => $timestamp] + $raw)
            );
        }
        self::assertNull(Max::fromArray(['update_type' => 'bot_stopped', 'user' => 'x'] + $raw));
        self::assertSame('', Max::fromArray(self::max())->membership);
    }
    public function testTelegramOtherContentKinds(): void
    {
        $raw = self::telegram('');
        $raw['message'] += [
            'voice' => ['file_id' => 'v'],
            'sticker' => ['file_id' => 's'],
            'venue' => ['title' => 't'],
            'location' => ['latitude' => 1, 'longitude' => 2],
            'dice' => ['emoji' => 'x', 'value' => 3],
            'poll' => ['id' => 'p'],
            'contact' => ['phone_number' => '1'],
            'audio' => ['file_id' => 'a'],
            'video' => ['file_id' => 'vi'],
            'video_note' => ['file_id' => 'vn'],
            'unknown_kind' => ['x' => 1],
            'new_chat_members' => [['id' => 1]],
            'game' => 'not-an-object',
        ];
        self::assertSame(
            [
                'voice',
                'sticker',
                'location',
                'other',
                'poll',
                'contact',
                'audio',
                'video',
                'video_note',
            ],
            Telegram::fromArray($raw)->otherContent
        );
        $raw = self::telegram('');
        $raw['message'] += [
            'animation' => ['file_id' => 'gif'],
            'document' => ['file_id' => 'gif', 'mime_type' => 'video/mp4'],
        ];
        $u = Telegram::fromArray($raw);
        self::assertSame(['animation'], $u->otherContent);
        self::assertSame([], $u->attachments);
        // Содержимое без своего вида – other, а не пустое сообщение.
        $kinds = [
            'story',
            'game',
            'paid_media',
            'invoice',
            'giveaway',
            'giveaway_winners',
            'checklist',
        ];
        foreach ($kinds as $key) {
            $raw = self::telegram('');
            $raw['message'][$key] = ['id' => 1];
            self::assertSame(['other'], Telegram::fromArray($raw)->otherContent, $key);
        }
        $raw = self::telegram('');
        $raw['message']['photo'] = [['file_id' => 'p', 'width' => 1, 'height' => 1]];
        $raw['message']['voice'] = 'not-an-object';
        $u = Telegram::fromArray($raw);
        self::assertSame([], $u->otherContent);
        self::assertCount(1, $u->attachments);
        $msg = self::telegram()['message'];
        $msg['voice'] = ['file_id' => 'v'];
        $callback = Telegram::fromArray([
            'update_id' => 14,
            'callback_query' => ['id' => 'cq', 'from' => ['id' => 42], 'message' => $msg],
        ]);
        self::assertTrue($callback->isCallback());
        self::assertSame([], $callback->otherContent);
    }
    public function testMaxOtherContentKinds(): void
    {
        $raw = self::max();
        $raw['message']['body']['attachments'] = [
            ['type' => 'image', 'payload' => ['token' => 't', 'url' => 'https://x.test/i']],
            ['type' => 'audio', 'payload' => ['url' => 'https://x.test/a']],
            ['type' => 'inline_keyboard', 'payload' => ['buttons' => []]],
            ['type' => 'video', 'payload' => ['token' => 'v']],
            ['type' => 'sticker', 'payload' => ['code' => 's']],
            ['type' => 'audio', 'payload' => ['url' => 'https://x.test/b']],
            ['type' => 'location', 'latitude' => 1, 'longitude' => 2],
            ['type' => 'contact', 'payload' => ['vcf_info' => 'x']],
            ['type' => 'share', 'payload' => ['url' => 'https://x.test/s']],
            ['type' => 'future_kind'],
            ['type' => 'file', 'payload' => ['token' => 'f'], 'filename' => 'a.pdf'],
            ['type' => 42],
            'garbage',
        ];
        $u = Max::fromArray($raw);
        self::assertSame(
            ['audio', 'video', 'sticker', 'location', 'contact', 'other'],
            $u->otherContent
        );
        self::assertCount(2, $u->attachments);
        // Картинка и файл без токена и ссылки – other, а не пустое сообщение.
        $raw2 = self::max();
        $raw2['message']['body']['attachments'] = [
            ['type' => 'image', 'payload' => []],
            ['type' => 'file'],
        ];
        $u = Max::fromArray($raw2);
        self::assertSame([], $u->attachments);
        self::assertSame(['other'], $u->otherContent);
        // Битые элементы (не объект, без строкового type) – не содержимое собеседника.
        $raw2['message']['body']['attachments'] = ['garbage', ['type' => 42], ['payload' => []]];
        self::assertSame([], Max::fromArray($raw2)->otherContent);
        $raw['update_type'] = 'message_callback';
        $raw['callback'] = ['callback_id' => 'c', 'payload' => 'go', 'user' => ['user_id' => 19]];
        $u = Max::fromArray($raw);
        self::assertTrue($u->isCallback());
        self::assertSame([], $u->otherContent);
    }
    public function testRawKeepsTheSourceUpdate(): void
    {
        self::assertSame(self::telegram(), Telegram::fromArray(self::telegram())->raw);
        self::assertSame(self::max(), Max::fromArray(self::max())->raw);
        $membership = self::telegramMembership('kicked');
        self::assertSame($membership, Telegram::fromArray($membership)->raw);
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
