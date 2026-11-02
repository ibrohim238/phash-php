<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Звено цепочки декодеров: переводит файл изображения в оттенки серого так же, как `PIL.Image.open(...).convert('L')`.
 */
interface ImageDecoderInterface
{
    /**
     * @return GrayscaleImage|null null — файл не этого формата или вариант формата не поддерживается, очередь следующего декодера
     *
     * @throws PhashException Файл этого формата, но повреждён
     */
    public function decode(string $contents): ?GrayscaleImage;
}
