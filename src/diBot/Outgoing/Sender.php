<?php
namespace diBot\Outgoing;

use diBot\AbstractBotApi;
use diBot\Keyboard;
use diBot\Exception\{ApiException, MediaNotReadyException};

readonly final class Sender
{
    public function __construct(private AbstractBotApi $api, private bool $mediaEnabled = true)
    {
    }

    public function send(
        string $chatId,
        string $text,
        ?\Closure $mediaFactory = null,
        ?Keyboard $keyboard = null,
        ?string $fallbackText = null,
        bool $fallbackWhenBlocked = true
    ): array {
        $plainText = $fallbackText ?? $text;
        if ($this->mediaEnabled && $mediaFactory !== null) {
            try {
                $media = $mediaFactory();
                if ($media !== null) {
                    if (!($media instanceof Media)) {
                        throw new \LogicException('Factory must return Media or null');
                    }
                    $media = $media->withCaption($text);
                    $decision = Policy::decide($media, $this->api);
                    if ($decision->transport !== Transport::Refuse) {
                        return $this->api->sendMedia(
                            $chatId,
                            $media,
                            $decision->transport,
                            $keyboard
                        );
                    }
                    $this->api->log('Media refused', ['reason' => $decision->reason]);
                    if ($decision->reason === 'caption_too_long') {
                        // Ограничение подписи не должно заменять основной текст короткой ссылкой.
                        $plainText = $text;
                    }
                }
            } catch (ApiException $e) {
                if (
                    $e instanceof MediaNotReadyException ||
                    $e->isDeliveryUncertain() ||
                    $e->rateLimitDelay() > 0 ||
                    (!$fallbackWhenBlocked && $e->blocked)
                ) {
                    throw $e;
                }
                $this->api->log('Media send rejected');
            } catch (\Throwable) {
                $this->api->log('Media send failed');
            }
        }
        // Вне try: ошибка запасного текста не должна отправить его повторно.
        return $this->api->sendMessage($chatId, $plainText, $keyboard);
    }
}
