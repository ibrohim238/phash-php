<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * XPM по логике Pillow 9.4.0 (XpmImagePlugin.py): один символ на пиксель, индекс палитры — код символа,
 * цвета только в виде #RRGGBB (None — прозрачный, остаётся чёрным), строки пикселей берутся
 * подряд после палитры без учёта комментариев.
 */
final readonly class XpmDecoder implements ImageDecoderInterface
{
    public function decode(string $contents): ?GrayscaleImage
    {
        if (!str_starts_with($contents, '/* XPM */')) {
            return null;
        }

        $lines    = preg_split('/(?<=\n)/', $contents);
        $position = 0;

        while (true) {
            $line = $lines[$position++] ?? throw PhashException::unreadableImage();

            if (preg_match('/^"([0-9]*) ([0-9]*) ([0-9]*) ([0-9]*)/', $line, $match)) {
                break;
            }
        }

        [, $width, $height, $paletteSize, $charsPerPixel] = array_map(intval(...), $match);

        if ($paletteSize > 256 || $charsPerPixel !== 1) {
            throw PhashException::unreadableImage();
        }

        $paletteLuma = str_repeat("\0", 256);

        for ($i = 0; $i < $paletteSize; $i++) {
            $line   = rtrim($lines[$position++] ?? '', "\r\n");
            $index  = ord($line[1]);
            $tokens = preg_split('/\s+/', substr($line, 2, -2), -1, PREG_SPLIT_NO_EMPTY);
            $found  = false;

            for ($t = 0; $t < count($tokens); $t += 2) {
                if ($tokens[$t] !== 'c') {
                    continue;
                }

                $color = $tokens[$t + 1] ?? '';

                if ($color !== 'None') {
                    if (!str_starts_with($color, '#')) {
                        throw PhashException::unreadableImage();
                    }

                    $rgb                 = hexdec(substr($color, 1));
                    $paletteLuma[$index] = chr(GrayscaleImage::luma(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255));
                }

                $found = true;
                break;
            }

            if (!$found) {
                throw PhashException::unreadableImage();
            }
        }

        $indexes = implode('', array_map(chr(...), range(0, 255)));
        $rows    = [];

        for ($y = 0; $y < $height; $y++) {
            $pixels = str_pad(substr($lines[$position++] ?? '', 1, $width), $width);
            $rows[] = strtr($pixels, $indexes, $paletteLuma);
        }

        return new GrayscaleImage($width, $height, $rows);
    }
}
