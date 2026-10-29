<?php
namespace diBot\Tests;

use diBot\Exception\SignatureException;
use diBot\Instagram\{Update, Webhook};
use diBot\Platform;
use PHPUnit\Framework\TestCase;

final class InstagramUpdateTest extends TestCase
{
    private const ACCOUNT = '17841400000000001';
    private const USER = '1234567890123456';

    /** Тело вебхука в форме примеров Meta: object, entry[] с messaging[] или changes[]. */
    public static function body(array ...$entries): string
    {
        return json_encode(['object' => 'instagram', 'entry' => $entries], JSON_THROW_ON_ERROR);
    }

    public static function messaging(array $item, string $account = self::ACCOUNT): array
    {
        return [
            'id' => $account,
            'time' => 1569262486134,
            'messaging' => [
                $item + [
                    'sender' => ['id' => self::USER],
                    'recipient' => ['id' => $account],
                    'timestamp' => 1569262485349,
                ],
            ],
        ];
    }

    public static function event(array $item, string $account = self::ACCOUNT): array
    {
        $body = self::body(self::messaging($item, $account));
        return (new Webhook('app-secret', 'verify'))->events($body, self::sign($body))[0];
    }

    public static function sign(string $body, string $secret = 'app-secret'): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testDirectText(): void
    {
        $event = self::event(['message' => ['mid' => 'aWdfZAG1', 'text' => '/start привет']]);
        $u = Platform::Instagram->parse($event);
        self::assertInstanceOf(Update::class, $u);
        self::assertSame(Platform::Instagram, $u->platform);
        self::assertSame(self::ACCOUNT, $u->accountId);
        self::assertSame(self::USER, $u->userId);
        self::assertSame(self::USER, $u->chatId);
        self::assertSame('aWdfZAG1', $u->messageId);
        self::assertSame('message:aWdfZAG1', $u->updateId);
        self::assertSame('/start привет', $u->text);
        // Команд у Instagram нет: текст остаётся текстом.
        self::assertSame('', $u->command);
        self::assertTrue($u->isPrivateChat);
        self::assertFalse($u->isCallback());
        self::assertNull($u->comment);
        self::assertSame($event, $u->raw);
        self::assertSame('', $u->userProfile['username']);
    }

    public function testQuickReplyCarriesPayloadAndText(): void
    {
        $u = Update::fromArray(
            self::event([
                'message' => [
                    'mid' => 'm2',
                    'text' => 'Да',
                    'quick_reply' => ['payload' => 'answer:1'],
                ],
            ])
        );
        self::assertSame('answer:1', $u->payload);
        self::assertSame('Да', $u->text);
        self::assertTrue($u->isCallback());
        self::assertSame('m2', $u->callbackId);
    }

    public function testPostbackIsCallback(): void
    {
        $u = Update::fromArray(
            self::event([
                'postback' => ['mid' => 'pb-1', 'title' => 'Готово', 'payload' => 'done'],
            ])
        );
        self::assertTrue($u->isCallback());
        self::assertSame('pb-1', $u->callbackId);
        self::assertSame('done', $u->payload);
        self::assertSame('postback:pb-1', $u->updateId);
        self::assertSame('', $u->text);
        self::assertNull(Update::fromArray(self::event(['postback' => ['payload' => 'x']])));
    }

    public function testAttachmentsAndOtherContent(): void
    {
        $u = Update::fromArray(
            self::event([
                'message' => [
                    'mid' => 'm3',
                    'attachments' => [
                        ['type' => 'image', 'payload' => ['url' => 'https://lookaside.fbsbx.com/i']],
                        ['type' => 'file', 'payload' => ['url' => 'https://lookaside.fbsbx.com/f']],
                        ['type' => 'audio', 'payload' => ['url' => 'https://lookaside.fbsbx.com/a']],
                        ['type' => 'video', 'payload' => ['url' => 'https://lookaside.fbsbx.com/v']],
                        ['type' => 'share', 'payload' => ['url' => 'https://lookaside.fbsbx.com/s']],
                        ['type' => 'image', 'payload' => []],
                        'broken',
                    ],
                ],
            ])
        );
        self::assertCount(2, $u->attachments);
        self::assertSame('photo', $u->attachments[0]->kind);
        self::assertSame('https://lookaside.fbsbx.com/i', $u->attachments[0]->url);
        self::assertSame('', $u->attachments[0]->ref);
        self::assertSame('document', $u->attachments[1]->kind);
        self::assertSame(['audio', 'video', 'other'], $u->otherContent);
        $unsupported = Update::fromArray(
            self::event(['message' => ['mid' => 'm4', 'is_unsupported' => true]])
        );
        self::assertSame(['other'], $unsupported->otherContent);
    }

    public function testEchoReadReactionDeletedEditedAreIgnored(): void
    {
        foreach (
            [
                ['message' => ['mid' => 'e', 'text' => 'hi', 'is_echo' => true]],
                ['message' => ['mid' => 'd', 'is_deleted' => true]],
                ['read' => ['mid' => 'r']],
                ['reaction' => ['mid' => 'x', 'action' => 'react', 'reaction' => 'love']],
                ['message_edit' => ['mid' => 'm', 'text' => 'new', 'num_edit' => 1]],
                ['referral' => ['ref' => 'r', 'source' => 'ADS']],
                ['message' => ['text' => 'no mid']],
            ]
            as $item
        ) {
            self::assertNull(Update::fromArray(self::event($item)), json_encode($item));
        }
        // Сообщение от самого аккаунта бизнеса без is_echo – тоже не входящее.
        $own = self::event(['message' => ['mid' => 'o', 'text' => 'hi']]);
        $own['messaging']['sender']['id'] = self::ACCOUNT;
        self::assertNull(Update::fromArray($own));
    }

    public function testCommentChange(): void
    {
        $body = self::body([
            'id' => self::ACCOUNT,
            'time' => 1700000000,
            'changes' => [
                [
                    'field' => 'comments',
                    'value' => [
                        'from' => ['id' => self::USER, 'username' => 'client'],
                        'media' => ['id' => '18000000000000001', 'media_product_type' => 'FEED'],
                        'id' => '17900000000000001',
                        'parent_id' => '17900000000000000',
                        'text' => 'хочу гайд',
                    ],
                ],
            ],
        ]);
        $event = (new Webhook('app-secret', 'verify'))->events($body, self::sign($body))[0];
        $u = Platform::Instagram->parse($event);
        self::assertSame('comment:17900000000000001', $u->updateId);
        self::assertSame(self::USER, $u->chatId);
        self::assertSame(self::USER, $u->userId);
        self::assertFalse($u->isPrivateChat);
        self::assertSame('хочу гайд', $u->text);
        self::assertSame('client', $u->userProfile['username']);
        self::assertSame('', $u->command);
        self::assertSame('17900000000000001', $u->comment->id);
        self::assertSame('18000000000000001', $u->comment->mediaId);
        self::assertSame('17900000000000000', $u->comment->parentId);
        self::assertSame('client', $u->comment->fromUsername);
        self::assertSame('1700000000', $u->comment->timestamp);
        self::assertFalse($u->comment->live);
    }

    public function testLiveCommentAndOwnOrForeignChanges(): void
    {
        $change = static fn(string $field, string $from) => [
            'object' => 'instagram',
            'entry_id' => self::ACCOUNT,
            'time' => 1,
            'change' => [
                'field' => $field,
                'value' => [
                    'from' => ['id' => $from, 'username' => 'u'],
                    'media' => ['id' => '1'],
                    'id' => '2',
                    'text' => 't',
                ],
            ],
        ];
        $live = Update::fromArray($change('live_comments', self::USER));
        self::assertTrue($live->comment->live);
        // Ответ самого бизнеса под постом не должен запускать бота повторно.
        self::assertNull(Update::fromArray($change('comments', self::ACCOUNT)));
        self::assertNull(Update::fromArray($change('mentions', self::USER)));
        $noEntry = $change('comments', self::USER);
        unset($noEntry['entry_id']);
        self::assertNull(Update::fromArray($noEntry));
        self::assertNull(Update::fromArray(['entry_id' => self::ACCOUNT]));
    }

    public function testMultiEntryBodyIsSplitIntoSelfContainedEvents(): void
    {
        $other = '17841400000000002';
        $body = self::body(
            [
                'id' => self::ACCOUNT,
                'time' => 10,
                'messaging' => [
                    ['sender' => ['id' => '1'], 'message' => ['mid' => 'a', 'text' => 'a']],
                    ['sender' => ['id' => '1'], 'message' => ['mid' => 'b', 'text' => 'b']],
                    'broken',
                ],
            ],
            [
                'id' => $other,
                'time' => 11,
                'changes' => [['field' => 'comments', 'value' => ['id' => 'c']]],
            ],
            ['time' => 12, 'messaging' => [['message' => ['mid' => 'lost']]]]
        );
        $events = (new Webhook('app-secret', 'verify'))->events($body, self::sign($body));
        self::assertCount(3, $events);
        self::assertSame(
            ['object', 'entry_id', 'time', 'messaging'],
            array_keys($events[0])
        );
        self::assertSame(self::ACCOUNT, $events[1]['entry_id']);
        self::assertSame('b', $events[1]['messaging']['message']['mid']);
        self::assertSame($other, $events[2]['entry_id']);
        self::assertSame(11, $events[2]['time']);
        self::assertSame('comments', $events[2]['change']['field']);
        // Событие переживает хранение строкой.
        $stored = json_decode(json_encode($events[0]), true);
        self::assertSame('message:a', Platform::Instagram->parse($stored)->updateId);
    }

    public function testIdsStayStringsAndBigIntsArePreserved(): void
    {
        $body =
            '{"object":"instagram","entry":[{"id":17841400000000001234,"time":1,' .
            '"messaging":[{"sender":{"id":"5"},"message":{"mid":"m","text":"x"}}]}]}';
        $events = (new Webhook('app-secret', 'verify'))->events($body, self::sign($body));
        self::assertSame('17841400000000001234', $events[0]['entry_id']);
    }

    public function testSignatureIsRequiredAndChecked(): void
    {
        $webhook = new Webhook('app-secret', 'verify');
        $body = self::body(self::messaging(['message' => ['mid' => 'm', 'text' => 'x']]));
        self::assertCount(1, $webhook->events($body, strtoupper(self::sign($body))));
        foreach (
            ['', 'sha256=', self::sign($body, 'other'), self::sign($body . ' '), 'sha1=abc']
            as $header
        ) {
            try {
                $webhook->events($body, $header);
                self::fail($header);
            } catch (SignatureException $e) {
                self::assertStringNotContainsString('app-secret', $e->getMessage());
            }
        }
    }

    public function testBodyLimitAndForeignObjects(): void
    {
        $webhook = new Webhook('app-secret', 'verify');
        self::assertSame(5 * 1024 * 1024, Webhook::MAX_BODY_BYTES);
        // Пакет Meta в 2 МБ (больше прежнего лимита) проходит.
        $batch = self::body(self::messaging(['message' => ['mid' => 'm', 'text' => str_repeat('x', 2 * 1024 * 1024)]]));
        self::assertCount(1, $webhook->events($batch, self::sign($batch)));
        $large = str_repeat(' ', Webhook::MAX_BODY_BYTES + 1);
        try {
            $webhook->events($large, self::sign($large));
            self::fail();
        } catch (\LengthException) {
        }
        foreach (['{bad', '"str"', '{"object":"page","entry":[{"id":"1","messaging":[{}]}]}'] as $b) {
            self::assertSame([], $webhook->events($b, self::sign($b)));
        }
    }

    public function testChallenge(): void
    {
        $webhook = new Webhook('app-secret', 'verify-token');
        $ok = ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-token', 'hub.challenge' => '1158201444'];
        self::assertSame('1158201444', $webhook->challenge($ok));
        // Так приходит из $_GET: PHP меняет точки на подчёркивания.
        self::assertSame(
            '42',
            $webhook->challenge([
                'hub_mode' => 'subscribe',
                'hub_verify_token' => 'verify-token',
                'hub_challenge' => '42',
            ])
        );
        self::assertNull($webhook->challenge(['hub.verify_token' => 'wrong'] + $ok));
        self::assertNull($webhook->challenge(['hub.mode' => 'unsubscribe'] + $ok));
        self::assertNull($webhook->challenge(['hub.challenge' => '<script>'] + $ok));
        self::assertNull($webhook->challenge(['hub.verify_token' => ['verify-token']] + $ok));
        self::assertNull($webhook->challenge([]));
        self::assertStringNotContainsString('verify-token', print_r($webhook, true));
        self::assertStringNotContainsString('app-secret', print_r($webhook, true));
    }
}
