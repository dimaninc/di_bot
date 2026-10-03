<?php
namespace diBot\Attachment;

final class KindResolver
{
    /**
     * Описательный тип по MIME или расширению; решение об inline-отдаче файла принимает
     * приложение. Не способ отправки: в sendFileId() и Media::kind не передавать – файл,
     * помеченный здесь photo, Telegram отправит sendPhoto и откажет, а MAX сочтёт картинкой.
     */
    public static function resolve(string $mime, string $filename = ''): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (str_starts_with($mime, 'image/')) {
            return 'photo';
        }
        if ($mime !== '' && $mime !== 'application/octet-stream') {
            return 'document';
        }
        return in_array(
            strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'tif', 'tiff'],
            true
        )
            ? 'photo'
            : 'document';
    }
}
