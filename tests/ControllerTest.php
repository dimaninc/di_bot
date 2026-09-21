<?php
namespace diBot\Tests;
use diBot\{AbstractController, AbstractUpdate, Config, CursorStore};
use diBot\Telegram\BotApi;
use PHPUnit\Framework\TestCase;

final class TestController extends AbstractController
{
    public array $handled = [];
    public bool $fail = false;
    protected function handle(AbstractUpdate $update): void
    {
        if ($this->fail) {
            throw new \RuntimeException('SECRET user message');
        }
        $this->handled[] = $update;
    }
}
final class MemoryCursor implements CursorStore
{
    public array $saves = [];
    public function __construct(
        public ?string $cursor = '2',
        public bool $failRead = false,
        public bool $failWrite = false
    ) {
    }
    public function load(): ?string
    {
        if ($this->failRead) {
            throw new \RuntimeException('store failed');
        }
        return $this->cursor;
    }
    public function save(?string $cursor): void
    {
        if ($this->failWrite) {
            throw new \RuntimeException('store failed');
        }
        $this->saves[] = $cursor;
        $this->cursor = $cursor;
    }
}
final class ControllerTest extends TestCase
{
    public function testWebhookAlwaysAcknowledgesIncludingHandlerAndLoggerFailures(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $controller = new TestController(
            new BotApi(new Config('123:token', webhookSecret: 'secret'), new FakeClient(), $logger)
        );
        $payload = json_encode(UpdateTest::telegram());
        self::assertSame(['ok' => true], $controller->webhook($payload, 'wrong'));
        self::assertCount(0, $controller->handled);
        self::assertSame(['ok' => true], $controller->webhook($payload, 'secret'));
        self::assertCount(1, $controller->handled);
        $controller->fail = true;
        foreach ([$payload, '{bad', 'null', str_repeat('x', 1024 * 1024 + 1)] as $raw) {
            self::assertSame(['ok' => true], $controller->webhook($raw, 'secret'));
        }
        $disabled = new TestController(
            new BotApi(new Config('', webhookSecret: 'secret'), new FakeClient())
        );
        self::assertSame(['ok' => true], $disabled->webhook($payload, 'secret'));
        self::assertCount(0, $disabled->handled);
    }
    public function testBrokenStoreAndInvalidCursorDoNotTouchWebhook(): void
    {
        foreach (
            [
                new MemoryCursor(failRead: true),
                new MemoryCursor(failWrite: true),
                new MemoryCursor('invalid'),
            ]
            as $store
        ) {
            $http = new FakeClient();
            $controller = new TestController(new BotApi(new Config('123:token'), $http));
            try {
                $controller->poll($store, 1);
                self::fail();
            } catch (\RuntimeException | \InvalidArgumentException) {
            }
            self::assertCount(0, $http->requests);
        }
    }
    public function testCheckpointOnlyAfterSuccessfulBatch(): void
    {
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => true]),
            FakeClient::json(['ok' => true, 'result' => [UpdateTest::telegram()]]),
        ]);
        $controller = new TestController(new BotApi(new Config('123:token'), $http));
        $store = new MemoryCursor();
        $controller->poll($store, 1);
        self::assertSame(['2', '13'], $store->saves);
        self::assertCount(1, $controller->handled);
        self::assertStringEndsWith('/deleteWebhook', $http->requests[0]->url);
        $http = new FakeClient([
            FakeClient::json(['ok' => true, 'result' => true]),
            FakeClient::json(['ok' => true, 'result' => [UpdateTest::telegram()]]),
        ]);
        $controller = new TestController(new BotApi(new Config('123:token'), $http));
        $controller->fail = true;
        $store = new MemoryCursor();
        try {
            $controller->poll($store, 1);
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame(['2'], $store->saves);
    }
}
