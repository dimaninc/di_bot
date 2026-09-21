<?php
namespace diBot\Outgoing;

use diBot\AbstractBotApi;
use diBot\Platform;
use diBot\Text;

final class Policy
{
    public static function decide(Media $media, AbstractBotApi $api): Decision
    {
        if ($media->size > $api::FILE_MAX_BYTES) {
            return new Decision(Transport::Refuse, 'too_large');
        }
        if (Text::length($media->caption) > $api::CAPTION_MAX_UNITS) {
            return new Decision(Transport::Refuse, 'caption_too_long');
        }
        // Telegram не всегда достигает российских хостов; локальный файл предпочтителен.
        if ($media->localPath !== '') {
            return new Decision(Transport::Upload);
        }
        if (
            $api->platform() === Platform::Max &&
            $media->kind === 'photo' &&
            $media->publicUrl !== '' &&
            $media->size <= 5 * 1024 * 1024
        ) {
            return new Decision(Transport::Url);
        }
        return new Decision(Transport::Refuse, 'no_local_source');
    }
}
