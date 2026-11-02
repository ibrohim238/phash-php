<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\GrayscaleImage;

/**
 * Targa (TgaImagePlugin.py, TgaRleDecode.c): палитровые, серые (в том числе с альфой) и RGB(A) варианты,
 * с RLE и без, порядок строк и зеркалирование по флагам ориентации.
 */
final readonly class TgaDecoder implements ImageDecoderInterface
{
    /** [тип изображения & 7, глубина] → распаковщик и байт на пиксель */
    private const MODES = [
        '1,8'  => ['P', 1],
        '3,1'  => ['1', 1],
        '3,8'  => ['L', 1],
        '3,16' => ['LA', 2],
        '2,16' => ['BGR;5', 2],
        '2,24' => ['BGR', 3],
        '2,32' => ['BGRA', 4],
    ];

    private static function accepts(string $contents): bool
    {
        if (strlen($contents) < 18) {
            return false;
        }

        $header = unpack('Cid/Ccolormap/Ctype/vmapStart/vmapLength/CmapDepth/x4/vwidth/vheight/Cdepth/Cflags', $contents);

        return in_array($header['colormap'], [0, 1], true)
            && in_array($header['type'], [1, 2, 3, 9, 10, 11], true)
            && in_array($header['depth'], [1, 8, 16, 24, 32], true)
            && $header['width'] > 0 && $header['height'] > 0
            && in_array($header['flags'] & 0x30, [0, 0x10, 0x20, 0x30], true)
            && isset(self::MODES[($header['type'] & 7) . ',' . $header['depth']]);
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $header                                                     = unpack('Cid/Ccolormap/Ctype/vmapStart/vmapLength/CmapDepth/x4/vwidth/vheight/Cdepth/Cflags', $contents);
        ['width' => $width, 'height' => $height, 'depth' => $depth] = $header;
        [$rawMode, $bytesPerPixel]                                  = self::MODES[($header['type'] & 7) . ',' . $depth];
        $topDown                                                    = in_array($header['flags'] & 0x30, [0x20, 0x30], true);
        $mirrored                                                   = in_array($header['flags'] & 0x30, [0x10, 0x30], true);
        $position                                                   = 18 + $header['id'];
        $palette                                                    = null;

        if ($header['colormap']) {
            $entrySize = intdiv($header['mapDepth'], 8);
            $palette   = self::paletteLuma($header['mapStart'], substr($contents, $position, $entrySize * $header['mapLength']), $header['mapDepth']);
            $position += $entrySize * $header['mapLength'];
        }

        $stride = $rawMode === '1' ? ($width + 7) >> 3 : $width * $bytesPerPixel;
        $data   = $header['type'] & 8
            ? self::decodeRle($contents, $position, $stride * $height, $bytesPerPixel)
            : substr($contents, $position, $stride * $height);
        $rows = array_fill(0, $height, str_repeat("\0", $width));

        for ($line = 0; $line < $height; $line++) {
            $bytes = substr($data, $line * $stride, $stride);

            if (strlen($bytes) < $stride) {
                break;
            }

            $row                                          = self::unpack($bytes, $width, $rawMode, $palette);
            $rows[$topDown ? $line : $height - 1 - $line] = $mirrored ? strrev($row) : $row;
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function paletteLuma(int $start, string $entries, int $depth): string
    {
        $luma = str_repeat("\0", 256);
        $size = intdiv($depth, 8);

        for ($index = $start, $i = 0; $index < 256 && $i + $size <= strlen($entries); $index++, $i += $size) {
            $luma[$index] = chr(self::pixelLuma(substr($entries, $i, $size), match ($depth) {
                16 => 'BGR;5', 24 => 'BGR', 32 => 'BGRA'
            }));
        }

        return $luma;
    }

    /**
     * Поток RLE Targa: пакеты «повтор» (старший бит) и «литерал», без привязки к границам строк.
     */
    private static function decodeRle(string $contents, int $position, int $total, int $bytesPerPixel): string
    {
        $data   = '';
        $length = strlen($contents);

        while (strlen($data) < $total && $position < $length) {
            $packet = ord($contents[$position]);
            $count  = ($packet & 0x7F) + 1;

            if ($packet & 0x80) {
                $data .= str_repeat(substr($contents, $position + 1, $bytesPerPixel), $count);
                $position += 1 + $bytesPerPixel;
            } else {
                $data .= substr($contents, $position + 1, $count * $bytesPerPixel);
                $position += 1 + $count * $bytesPerPixel;
            }
        }

        return substr($data, 0, $total);
    }

    private static function unpack(string $bytes, int $width, string $rawMode, ?string $palette): string
    {
        $row = '';

        for ($x = 0; $x < $width; $x++) {
            $row .= match ($rawMode) {
                '1'     => (ord($bytes[$x >> 3]) >> (7 - ($x & 7))) & 1 ? "\xFF" : "\0",
                'L'     => $bytes[$x],
                'LA'    => $bytes[$x * 2],
                'P'     => $palette[ord($bytes[$x])],
                'BGR;5' => chr(self::pixelLuma(substr($bytes, $x * 2, 2), 'BGR;5')),
                'BGR'   => chr(self::pixelLuma(substr($bytes, $x * 3, 3), 'BGR')),
                'BGRA'  => chr(self::pixelLuma(substr($bytes, $x * 4, 4), 'BGRA')),
            };
        }

        return $row;
    }

    private static function pixelLuma(string $pixel, string $rawMode): int
    {
        if ($rawMode === 'BGR;5') {
            $value = ord($pixel[0]) + (ord($pixel[1]) << 8);

            return GrayscaleImage::luma(
                intdiv((($value >> 10) & 31) * 255, 31),
                intdiv((($value >> 5) & 31) * 255, 31),
                intdiv(($value & 31) * 255, 31),
            );
        }

        return GrayscaleImage::luma(ord($pixel[2]), ord($pixel[1]), ord($pixel[0]));
    }
}
