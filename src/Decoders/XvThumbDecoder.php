<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Миниатюра XV «P7 332» (XVThumbImagePlugin.py): байт на пиксель с фиксированной палитрой RGB 3-3-2.
 */
final readonly class XvThumbDecoder implements ImageDecoderInterface
{
    private static function accepts(string $contents): bool
    {
        return str_starts_with($contents, 'P7 332');
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $position = strpos($contents, "\n", 6);

        while ($position !== false) {
            $position++;
            $end = strpos($contents, "\n", $position);

            if ($end === false) {
                throw PhashException::unreadableImage();
            }

            if ($contents[$position] !== '#') {
                break;
            }

            $position = $end;
        }

        [$width, $height] = array_map(intval(...), preg_split('/\s+/', trim(substr($contents, $position, $end - $position))));
        $position         = $end + 1;
        $palette          = '';

        for ($index = 0; $index < 256; $index++) {
            $palette .= chr(GrayscaleImage::luma(
                intdiv((($index >> 5) & 7) * 255, 7),
                intdiv((($index >> 2) & 7) * 255, 7),
                intdiv(($index & 3) * 255, 3),
            ));
        }

        $indexes = implode('', array_map(chr(...), range(0, 255)));
        $rows    = array_fill(0, $height, str_repeat($palette[0], $width));

        for ($y = 0; $y < $height; $y++) {
            $line = substr($contents, $position + $y * $width, $width);

            if (strlen($line) < $width) {
                break;
            }

            $rows[$y] = strtr($line, $indexes, $palette);
        }

        return new GrayscaleImage($width, $height, $rows);
    }
}
