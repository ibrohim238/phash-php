<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Растровый формат PIXAR (PixarImagePlugin.py): заголовок 1024 байта, размеры по смещениям 416/418,
 * далее сырой RGB по три байта на пиксель.
 */
final readonly class PixarDecoder implements ImageDecoderInterface
{
    private static function accepts(string $contents): bool
    {
        return str_starts_with($contents, "\x80\xE8\x00\x00");
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $width  = unpack('v', $contents, 418)[1];
        $height = unpack('v', $contents, 416)[1];

        if ([unpack('v', $contents, 424)[1], unpack('v', $contents, 426)[1]] !== [14, 2]) {
            throw PhashException::unreadableImage();
        }

        $rows = array_fill(0, $height, str_repeat("\0", $width));

        for ($y = 0; $y < $height; $y++) {
            $line = substr($contents, 1024 + $y * $width * 3, $width * 3);

            if (strlen($line) < $width * 3) {
                break;
            }

            $row = '';

            for ($x = 0; $x < $width; $x++) {
                $row .= chr(GrayscaleImage::luma(ord($line[$x * 3]), ord($line[$x * 3 + 1]), ord($line[$x * 3 + 2])));
            }

            $rows[$y] = $row;
        }

        return new GrayscaleImage($width, $height, $rows);
    }
}
