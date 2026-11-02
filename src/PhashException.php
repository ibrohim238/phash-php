<?php

namespace Ibrohim238\Phash;

use RuntimeException;

final class PhashException extends RuntimeException
{
    public static function unreadableImage(): self
    {
        return new self('Не удалось прочитать изображение для расчёта phash');
    }
}
