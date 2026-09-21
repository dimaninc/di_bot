<?php
namespace diBot\Outgoing;

use diBot\AbstractBotApi;
use diBot\Keyboard;

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
        ?string $fallbackText = null
    ): array {
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
                }
            } catch (\Throwable) {
                $this->api->log('Media send failed');
            }
        }
        // Вне try: ошибка запасного текста не должна отправить его повторно.
        return $this->api->sendMessage($chatId, $fallbackText ?? $text, $keyboard);
    }
}
