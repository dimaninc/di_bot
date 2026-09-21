<?php
namespace diBot;

abstract class AbstractController
{
    public function __construct(protected AbstractBotApi $api)
    {
    }
    abstract protected function handle(AbstractUpdate $update): void;

    protected function pollingStarted(): void
    {
    }

    /** $secret – значение заголовка Telegram или уже декодированный сегмент URL MAX. */
    public function webhook(string $rawBody, string $secret): array
    {
        try {
            $config = $this->api->config;
            if (
                $config->token === '' ||
                $config->webhookSecret === '' ||
                !hash_equals($config->webhookSecret, $secret)
            ) {
                return ['ok' => true];
            }
            if (strlen($rawBody) > 1024 * 1024) {
                throw new \LengthException('Update too large');
            }
            $data = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($data)) {
                return ['ok' => true];
            }
            $update = $this->api->platform()->parse($data);
            if ($update !== null) {
                $this->handle($update);
            }
        } catch (\Throwable) {
            $this->api->log('Webhook processing failed');
        }
        return ['ok' => true];
    }

    public function emitWebhook(string $rawBody, string $secret): void
    {
        $response = $this->webhook($rawBody, $secret);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** Хранилище обязано бросать при ошибке; сохраняем позицию только после обработки всей пачки. */
    public function poll(
        CursorStore $store,
        int $maxBatches = 0,
        ?\Closure $shouldContinue = null
    ): void {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Polling is only available in CLI');
        }
        if ($maxBatches < 0) {
            throw new \InvalidArgumentException('Invalid batch limit');
        }
        $cursor = $store->load();
        $this->api->validateCursor($cursor);
        // Проверяем запись ДО снятия вебхука и чтения событий.
        $store->save($cursor);
        $this->api->deleteWebhook();
        $this->pollingStarted();
        for ($batch = 0; $maxBatches === 0 || $batch < $maxBatches; $batch++) {
            if ($shouldContinue !== null && !$shouldContinue()) {
                break;
            }
            $started = microtime(true);
            $result = $this->api->getUpdates($cursor);
            foreach ($result->updates as $update) {
                $this->handle($update);
            }
            $store->save($result->cursor);
            $cursor = $result->cursor;
            if ($result->updates === [] && microtime(true) - $started < 0.1) {
                usleep(100000);
            }
        }
    }
}
